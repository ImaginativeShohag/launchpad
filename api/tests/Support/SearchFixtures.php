<?php

use App\Actions\Search\SearchPage;
use App\Actions\Search\SearchProducts;
use App\Actions\Search\SearchQuery;
use App\Enums\SearchSort;
use App\Models\Product;
use App\Models\Topic;
use App\Rules\KeysetCursor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** A live, launched product (visible to the public) with the given searchable text; never set the generated columns. */
function searchProduct(array $attributes = []): Product
{
    return Product::factory()->live()->create($attributes);
}

/**
 * The EXPLAIN text of a query with some planner settings switched off (for example ['seqscan']), restored afterwards.
 *
 * @param  list<string>  $off  planner switches without the `enable_` prefix
 * @param  list<mixed>  $bindings
 */
function searchPlan(string $sql, array $bindings = [], array $off = ['seqscan']): string
{
    foreach ($off as $name) {
        DB::statement("SET enable_{$name} = off");
    }

    try {
        return collect(DB::select('EXPLAIN '.$sql, $bindings))->map(fn ($row) => array_values((array) $row)[0])->implode("\n");
    } finally {
        foreach ($off as $name) {
            DB::statement("RESET enable_{$name}");
        }
    }
}

/** The ids of the products a full-text word or prefix finds: whole words first, the last token as a prefix. */
function searchVectorHits(string $whole, string $prefix = ''): array
{
    $sql = $prefix === ''
        ? 'select id from products where search_vector @@ plainto_tsquery(\'simple\', launchpad_fold(?))'
        : 'select id from products where search_vector @@ (plainto_tsquery(\'simple\', launchpad_fold(?)) && launchpad_prefix_tsquery(?))';

    return array_column(DB::select($sql, $prefix === '' ? [$whole] : [$whole, $prefix]), 'id');
}

/** The ids found through the trigram arm (typo tolerance on the folded name). */
function searchTrigramHits(string $typed): array
{
    return array_column(DB::select('select id from products where launchpad_fold(?) <% name_search', [$typed]), 'id');
}

/**
 * A temporary copy of the products table for plan tests: the generated columns and the two search indexes are replayed from
 * the REAL definitions (read from pg_catalog), then $filler synthetic live rows and every row of `products` are added and
 * the copy is analysed. On the real table the planner's choice between the GIN indexes and the ordinary btree indexes on
 * `status` depends on table size, bloat from earlier rolled-back tests and default selectivity guesses, so a plan assertion
 * there is flaky; on the copy only the search indexes exist, which is what these tests want to prove (the predicate and the
 * operators reach them). Returns the temporary table name.
 */
function searchScratchProducts(int $filler = 3000): string
{
    $table = 'zz_search_products';
    DB::statement("drop table if exists pg_temp.{$table}");
    DB::statement("create temporary table {$table} (like products including defaults including generated)");

    foreach (['products_search_vector_gin' => 'zz_vec_gin', 'products_name_search_trgm_gin' => 'zz_trgm_gin'] as $name => $copy) {
        $definition = (string) DB::table('pg_indexes')->where('tablename', 'products')->where('indexname', $name)->value('indexdef');
        DB::statement(str_replace([$name, 'ON public.products'], [$copy, "ON pg_temp.{$table}"], $definition));
    }

    DB::statement("insert into {$table} (id, maker_id, slug, name, tagline, description, status, deleted_at, created_at, updated_at)
        select id, maker_id, slug, name, tagline, description, status, deleted_at, created_at, updated_at from products");
    DB::statement("insert into {$table} (id, maker_id, slug, name, tagline, description, status, created_at, updated_at)
        select lpad(g::text, 26, '0'), (select maker_id from products limit 1), 'filler-' || g, 'Filler ' || g, 'Tagline ' || g,
               'Plain text number ' || g || ' about nothing in particular', 'live', now(), now()
        from generate_series(1, {$filler}) g");
    DB::statement("analyze {$table}");

    return $table;
}

function searchFold(?string $input): ?string
{
    return DB::selectOne('select launchpad_fold(?) as folded', [$input])->folded;
}

/** Leaves only bitmap scans on, so a tiny table cannot make the planner pick the ordered votes index instead of a GIN index. */
function searchBitmapOnly(): array
{
    return ['seqscan', 'indexscan', 'indexonlyscan'];
}

function searchSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

const SEARCH_RELEVANCE_SHAPE = ['h' => 'hash', 'score' => 'decimal', 'votes_count' => 'int', 'id' => 'ulid'];
const SEARCH_VOTES_SHAPE = ['h' => 'hash', 'votes_count' => 'int', 'launch_date' => 'day', 'id' => 'ulid'];
const SEARCH_NEWEST_SHAPE = ['h' => 'hash', 'launched_at' => 'date', 'id' => 'ulid'];

function searchCursorOf(mixed $payload): string
{
    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
}

function searchCursorPasses(mixed $value, array $shape): bool
{
    return Validator::make(['cursor' => $value], ['cursor' => [new KeysetCursor($shape)]])->passes();
}

/** A valid relevance cursor payload with one value replaced. */
function searchRelevancePayload(array $override = []): array
{
    return [...['h' => 'abcdef012345', 'score' => '3.2500', 'votes_count' => 4, 'id' => (string) Str::ulid(), '_pointsToNextItems' => true], ...$override];
}

function searchTopicLink(Product $product, Topic $topic): void
{
    DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
}

/** @return list<string> */
function searchTokens(string $input): array
{
    return SearchQuery::fromString($input)->tokens();
}

/** A live, launched product with exactly this searchable text (the factory's default tagline and description would match common words). */
function searchLive(string $name, ?string $tagline = null, ?string $description = null, array $attributes = []): Product
{
    return Product::factory()->live()->create(['name' => $name, 'tagline' => $tagline, 'description' => $description, ...$attributes]);
}

/** Runs the action the way the request would resolve its arguments (a blank query is none; the default sort follows). */
function searchRun(?string $q, ?string $topic = null, ?SearchSort $sort = null, int $perPage = 50, ?string $cursor = null): SearchPage
{
    $query = $q === null ? null : SearchQuery::fromString($q);
    $query = $query !== null && ! $query->isEmpty() ? $query : null;
    $sort ??= $query === null ? SearchSort::Votes : SearchSort::Relevance;

    return app(SearchProducts::class)->handle($query, $topic, $sort, $perPage, $cursor);
}

/** @return list<string> the names found, in result order */
function searchNames(?string $q, ?string $topic = null, ?SearchSort $sort = null): array
{
    return searchRun($q, $topic, $sort)->products->pluck('name')->all();
}

/** Every SQL statement text issued while the callback runs. @return list<string> */
function searchCaptureSql(Closure $callback): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->toRawSql();
    });
    $callback();

    return $sql;
}

/**
 * The states a product can be in without being public, each really created (a CHECK constraint refuses some combinations),
 * with the searchable word `quokka` in its name. Returns label => [builder, expected status, hidden-by-flag].
 *
 * @return array<string, array{0: Closure(): Product, 1: string}>
 */
function searchHiddenStates(): array
{
    $named = ['name' => 'Quokka Hidden'];
    $topic = fn (Product $product): Product => tap($product, fn () => searchTopicLink($product, Topic::query()->firstOrCreate(['slug' => 'hidden-topic'], ['name' => 'Hidden topic', 'position' => 1])));

    return [
        'draft' => [fn () => $topic(Product::factory()->create($named)), 'draft'],
        'pending' => [fn () => $topic(Product::factory()->pending()->create($named)), 'pending'],
        'changes_requested' => [fn () => $topic(Product::factory()->changesRequested()->create($named)), 'changes_requested'],
        'approved' => [fn () => $topic(Product::factory()->approved()->create($named)), 'approved'],
        'scheduled' => [fn () => $topic(Product::factory()->scheduled()->create($named)), 'scheduled'],
        'rejected' => [fn () => $topic(Product::factory()->rejected()->create($named)), 'rejected'],
        'taken_down' => [fn () => $topic(Product::factory()->takenDown()->create($named)), 'taken_down'],
        'soft_deleted' => [fn () => $topic(tap(Product::factory()->live()->create($named), fn (Product $p) => $p->delete())), 'live'],
        'live_future_launch_date' => [fn () => $topic(Product::factory()->live(now('UTC')->addDays(3)->toDateString())->create($named)), 'live'],
        'live_deleted_maker' => [fn () => $topic(tap(Product::factory()->live()->create($named), fn (Product $p) => DB::table('users')->where('id', $p->maker_id)->update(['deleted_at' => now()]))), 'live'],
    ];
}

/**
 * Makes the analysed indexed copy of searchScratchProducts() answer to the name `products` (a temporary table shadows the
 * real one for unqualified names), so the SQL the action really runs can be EXPLAINed against it unchanged. The index names
 * on the copy are `zz_vec_gin` and `zz_trgm_gin`. Call searchUnshadowProducts() (or let the test's rollback do it) afterwards.
 */
function searchShadowProducts(int $filler = 3000): void
{
    $copy = searchScratchProducts($filler);
    DB::statement("alter table pg_temp.{$copy} rename to products");
}

function searchUnshadowProducts(): void
{
    DB::statement('drop table if exists pg_temp.products');
}

/** The one statement the action ran against `products`, bindings substituted (the card query, not the eager loads). */
function searchCaptureMainSql(Closure $callback): string
{
    $main = null;
    DB::listen(function ($query) use (&$main): void {
        $raw = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);

        if ($main === null && preg_match('/^select \* from (\(select "products"\.\*|"products")/', $raw) === 1) {
            $main = $raw;
        }
    });
    $callback();

    return (string) $main;
}

function searchApi(string $queryString = ''): TestResponse
{
    return test()->getJson('/api/v1/search'.($queryString === '' ? '' : '?'.$queryString));
}

/** A topic with a known slug, and $count live "Quokka Tool" products in it. @return list<Product> */
function searchTopicWithProducts(string $slug = 'tools', int $count = 3, array $attributes = []): array
{
    $topic = Topic::factory()->create(['slug' => $slug]);

    return collect(range(1, $count))->map(function () use ($topic, $attributes) {
        $product = searchLive('Quokka Tool', null, null, $attributes);
        searchTopicLink($product, $topic);

        return $product;
    })->all();
}
