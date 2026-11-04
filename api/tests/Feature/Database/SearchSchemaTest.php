<?php

use App\Actions\Search\SearchQuery;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

it('has the unaccent and pg_trgm extensions', function () {
    // Given the migrated database
    // When the installed extensions are read
    $names = DB::table('pg_extension')->pluck('extname')->all();

    // Then both search extensions are there
    expect($names)->toContain('unaccent')->toContain('pg_trgm');
});

it('folds text with launchpad_fold', function (string $input, string $expected) {
    // Given a typed or stored text
    // When it is folded
    $folded = searchFold($input);

    // Then accents, case, width, ligatures and ASCII punctuation are gone
    expect($folded)->toBe($expected);
})->with([
    'accent' => ['Café', 'cafe'],
    'upper accent' => ['CAFÉ', 'cafe'],
    'full width' => ['ＡＢＣ', 'abc'],
    'ligature' => ['ﬁne', 'fine'],
    'hyphen' => ['foo-bar', 'foo bar'],
    'version' => ['v2.1', 'v2 1'],
    'email' => ['a@b.co', 'a b co'],
    'spaces' => ['  A   B  ', 'a b'],
    'plus signs' => ['c++', 'c'],
    'bengali' => ['বাংলা কলম', 'বাংলা কলম'],
    'empty' => ['', ''],
    'mixed' => [' Café  FOO-bar v2.1 ＡＢＣ ', 'cafe foo bar v2 1 abc'],
    'cyrillic' => ['ПРИВЕТ', 'привет'],
    'greek' => ['ΑΒΓ', 'αβγ'],
]);

it('folds NULL to NULL and treats composed and decomposed Bengali ya the same', function () {
    // Given a NULL, and the Bengali letter ya with nukta in both of its Unicode forms
    // When they are folded
    $composed = searchFold("\u{09DF}");
    $decomposed = searchFold("\u{09AF}\u{09BC}");

    // Then NULL stays NULL (STRICT) and the two forms are equal
    expect(searchFold(null))->toBeNull()->and($composed)->toBe($decomposed);
});

it('declares the fold function immutable and the prefix function stable', function () {
    // Given the two functions
    // When their volatility is read
    $rows = DB::table('pg_proc')->whereIn('proname', ['launchpad_fold', 'launchpad_prefix_tsquery'])->get(['proname', 'provolatile', 'proparallel'])->keyBy('proname');

    // Then the fold is immutable and parallel safe (a generated column needs that), the prefix builder is stable
    expect($rows['launchpad_fold']->provolatile)->toBe('i')->and($rows['launchpad_fold']->proparallel)->toBe('s')
        ->and($rows['launchpad_prefix_tsquery']->provolatile)->toBe('s');
});

it('builds a prefix tsquery from any text without a syntax error', function (string $input, string $expected) {
    // Given a typed token, however odd
    // When the prefix tsquery is built
    $query = DB::selectOne('select launchpad_prefix_tsquery(?)::text as q', [$input])->q;

    // Then it never raises and holds only lexemes
    expect($query)->toBe($expected);
})->with([
    'plain' => ['laun', "'laun':*"],
    'empty' => ['', ''],
    'half sign' => ['½', "'1' & '2':*"],
    'single' => ['a', "'a':*"],
    'punctuation' => ['!!', ''],
    'injection' => ["'; DROP TABLE products; --", "'drop' & 'table' & 'products':*"],
    'operator inside' => ['a:*b', "'a' & 'b':*"],
    'backslash' => ['\\', ''],
]);

it('matches a prefix with launchpad_prefix_tsquery', function () {
    // Given a text and a typed prefix
    // When they are compared
    $hit = DB::selectOne("select to_tsvector('simple', 'bangla') @@ launchpad_prefix_tsquery('bang') as hit")->hit;

    // Then the prefix matches
    expect($hit)->toBeTrue();
});

it('marks both search columns as generated', function (string $column) {
    // Given the products table
    // When the column metadata is read
    $generated = DB::table('information_schema.columns')->where('table_name', 'products')->where('column_name', $column)->value('is_generated');

    // Then PostgreSQL computes it
    expect($generated)->toBe('ALWAYS');
})->with(['search_vector', 'name_search']);

it('weights name, tagline and description and folds the name', function () {
    // Given a product with a name, a tagline and a description
    $product = searchProduct(['name' => 'Launchpad', 'tagline' => 'Ship fast', 'description' => 'a tool for makers']);

    // When the generated columns are read
    $row = DB::table('products')->where('id', $product->id)->first(['search_vector', 'name_search']);

    // Then each part carries its weight
    expect($row->search_vector)->toContain("'launchpad':1A")->toContain("'ship':2B")->toContain("'fast':3B")
        ->toContain("'tool':")->toContain("'makers':")
        ->and($row->name_search)->toBe('launchpad');
    expect(preg_match("/'tool':\d+C/", $row->search_vector))->toBe(1)->and(preg_match("/'makers':\d+C/", $row->search_vector))->toBe(1);
});

it('follows edits by itself with no job and ignores unrelated updates', function () {
    // Given a live product that is found by a word in its description
    $product = searchProduct(['name' => 'Pad', 'description' => 'alpha beta']);
    $before = DB::table('products')->where('id', $product->id)->value('search_vector');

    // When only the vote counter changes
    $product->forceFill(['votes_count' => 5])->save();

    // Then the vector is unchanged
    expect(DB::table('products')->where('id', $product->id)->value('search_vector'))->toBe($before);

    // When the description is edited through Eloquent
    $product->forceFill(['description' => 'gamma delta'])->save();

    // Then the new words match at once and the removed ones do not
    expect(searchVectorHits('gamma'))->toBe([$product->id])->and(searchVectorHits('alpha'))->toBe([]);
});

it('inserts a product with no tagline and no description', function () {
    // Given a product whose optional text is NULL
    $product = searchProduct(['name' => 'Bare', 'tagline' => null, 'description' => null]);

    // When the columns are read
    $row = DB::table('products')->where('id', $product->id)->first(['search_vector', 'name_search']);

    // Then the name is still searchable and name_search is never NULL
    expect($row->name_search)->toBe('bare')->and($row->search_vector)->toBe("'bare':1A");
});

it('refuses a direct write to a generated column with SQLSTATE 428C9', function (string $column, string $value) {
    // Given a product
    $product = searchProduct();

    // When the generated column is written
    $state = searchSqlState(fn () => DB::table('products')->where('id', $product->id)->update([$column => $value]));

    // Then PostgreSQL refuses
    expect($state)->toBe('428C9');
})->with([['search_vector', "'x'"], ['name_search', 'x']]);

it('indexes only the first 20000 characters of the description', function () {
    // Given a 30,000 character description with one unique word before and one after character 20,000
    $product = searchProduct();
    $description = 'earlyword '.str_repeat('filler ', 3000).str_repeat('x', 100).' lateword '.str_repeat('padding ', 1500);
    DB::table('products')->where('id', $product->id)->update(['description' => $description]);

    // When the vector is searched
    // Then only the early word is in it
    expect(mb_strlen($description))->toBeGreaterThan(30000)
        ->and(searchVectorHits('earlyword'))->toBe([$product->id])
        ->and(searchVectorHits('lateword'))->toBe([]);
});

it('finds Bengali, Persian, Greek and Cyrillic text by word, prefix and typo', function () {
    // Given products named in several scripts
    $bengali = searchProduct(['name' => 'বাংলা কলম']);
    $persian = searchProduct(['name' => "می\u{200C}خواهم"]);
    $greek = searchProduct(['name' => 'ΑΒΓ Δέλτα']);
    $cyrillic = searchProduct(['name' => 'Привет Мир']);

    // When they are searched
    // Then a whole word, a prefix and a trigram typo find the right row
    expect(searchVectorHits('কলম'))->toBe([$bengali->id])
        ->and(searchVectorHits('', 'কল'))->toBe([$bengali->id])
        ->and(searchTrigramHits('কলম'))->toBe([$bengali->id])
        ->and(searchVectorHits("می\u{200C}خواهم"))->toBe([$persian->id])
        ->and(searchVectorHits('', "می\u{200C}خ"))->toBe([$persian->id])
        ->and(searchVectorHits('αβγ'))->toBe([$greek->id])
        ->and(searchVectorHits('ΑΒΓ'))->toBe([$greek->id])
        ->and(searchVectorHits('', 'ПРИ'))->toBe([$cyrillic->id])
        ->and(searchVectorHits('привет'))->toBe([$cyrillic->id]);
});

it('keeps a ZWNJ word and a ZWJ word as one lexeme', function () {
    // Given a Persian word with a ZWNJ and a Bengali conjunct with a ZWJ
    $word = "می\u{200C}خواهم";
    $conjunct = "ক্ষ\u{200D}ত্র";

    // When they are tokenised by PostgreSQL after the fold
    $persian = DB::selectOne("select to_tsvector('simple', launchpad_fold(?))::text as v", [$word])->v;
    $bengali = DB::selectOne("select to_tsvector('simple', launchpad_fold(?))::text as v", [$conjunct])->v;

    // Then each is a single lexeme
    expect(substr_count($persian, ':'))->toBe(1)->and(substr_count($bengali, ':'))->toBe(1);
});

it('does not split a Bengali conjunct and finds both forms of ya', function () {
    // Given a Bengali word with a conjunct, and a name written with the decomposed ya
    $pencil = searchProduct(['name' => 'বাংলা কলম পেন্সিল']);
    $ya = searchProduct(['name' => "\u{09DF}ন"]);

    // When they are searched
    $lexemes = DB::selectOne("select to_tsvector('simple', launchpad_fold(?))::text as v", ['বাংলা কলম পেন্সিল'])->v;

    // Then three lexemes, and either form of ya finds the row
    expect(substr_count($lexemes, ':'))->toBe(3)->and(searchVectorHits('পেন্সিল'))->toBe([$pencil->id])
        ->and(searchVectorHits("\u{09AF}\u{09BC}ন"))->toBe([$ya->id])->and(searchVectorHits("\u{09DF}ন"))->toBe([$ya->id]);
});

it('finds an accented name however the user types it', function (string $typed) {
    // Given a product named Café
    $product = searchProduct(['name' => 'Café']);

    // When it is searched
    // Then case and accents do not matter
    expect(searchVectorHits($typed))->toBe([$product->id]);
})->with(['cafe', 'CAFE', 'café', 'CAFÉ']);

it('creates two partial GIN indexes', function () {
    // Given the migrated database
    // When the index definitions are read
    $defs = DB::table('pg_indexes')->where('tablename', 'products')->whereIn('indexname', ['products_search_vector_gin', 'products_name_search_trgm_gin'])->pluck('indexdef', 'indexname')->all();

    // Then both are GIN, the second with trigram ops, and both carry the live predicate
    expect($defs)->toHaveCount(2);
    foreach ($defs as $def) {
        expect($def)->toContain('USING gin')->toContain('status')->toContain('deleted_at IS NULL');
    }
    expect($defs['products_name_search_trgm_gin'])->toContain('gin_trgm_ops');
});

it('keeps only live rows reachable through the partial indexes and needs no reindex on a status change', function () {
    // Given a live product, a draft, a taken-down one and a soft-deleted one that all carry the same word
    $live = searchProduct(['name' => 'Zebrafish']);
    $draft = Product::factory()->create(['name' => 'Zebrafish draft']);
    Product::factory()->takenDown()->create(['name' => 'Zebrafish down']);
    $deleted = searchProduct(['name' => 'Zebrafish gone']);
    $deleted->delete();
    $sql = "select id from %s where status = 'live' and deleted_at is null and search_vector @@ plainto_tsquery('simple', 'zebrafish')";

    // When the query that carries the index predicate runs on the real table, and its plan is read on the indexed copy
    $ids = array_column(DB::select(sprintf($sql, 'products')), 'id');
    $plan = searchPlan(sprintf($sql, searchScratchProducts()), [], searchBitmapOnly());

    // Then only the live row comes back, and the plan goes through the vector index
    expect($ids)->toBe([$live->id])->and($plan)->toContain('Bitmap Index Scan on zz_vec_gin');

    // When the draft is launched and the live one is taken down
    $draft->forceFill(['status' => 'live', 'launch_date' => now('UTC')->toDateString(), 'launched_at' => now(), 'submitted_at' => now()->subDay(), 'approved_at' => now()->subHour()])->save();
    $live->forceFill(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'x'])->save();

    // Then the results follow with no reindex step
    expect(array_column(DB::select(sprintf($sql, 'products')), 'id'))->toBe([$draft->id]);
});

it('needs the live predicate in the query before the planner uses the partial indexes', function () {
    // Given the indexed copy of an analysed table
    searchProduct(['name' => 'Zebrafish']);
    $table = searchScratchProducts();
    $with = "select id from {$table} where status = 'live' and deleted_at is null and search_vector @@ plainto_tsquery('simple', 'zebrafish')";
    $without = "select id from {$table} where search_vector @@ plainto_tsquery('simple', 'zebrafish')";

    // When both plans are read with only bitmap scans allowed
    // Then the predicate is load-bearing: without it the partial index cannot be used
    expect(searchPlan($with, [], searchBitmapOnly()))->toContain('zz_vec_gin')
        ->and(searchPlan($without, [], searchBitmapOnly()))->not->toContain('zz_vec_gin');
});

it('reaches both GIN indexes under a BitmapOr, also with bound parameters', function () {
    // Given the indexed copy of an analysed table
    searchProduct(['name' => 'Launchpad']);
    $table = searchScratchProducts();
    $sql = "select id from {$table} where status = 'live' and deleted_at is null and (search_vector @@ (plainto_tsquery('simple', launchpad_fold(?)) && launchpad_prefix_tsquery(?)) or launchpad_fold(?) <% name_search)";

    // When the planner is asked, with only bitmap scans allowed
    $plan = searchPlan($sql, ['foo', 'lau', 'lanchpad'], searchBitmapOnly());

    // Then both indexes are used together
    expect($plan)->toContain('BitmapOr')->toContain('Bitmap Index Scan on zz_vec_gin')->toContain('Bitmap Index Scan on zz_trgm_gin');
});

it('survives migrate:fresh because the functions are created with OR REPLACE', function () {
    // Given the functions already exist (migrate:fresh keeps them)
    $migration = require database_path('migrations/2026_10_21_000012_create_search_functions.php');

    // When the first migration runs again over them
    $migration->up();

    // Then it does not fail and the fold still works
    expect(searchFold('Café'))->toBe('cafe');
});

it('finds Persian and Bengali words typed through the PHP tokeniser, joiners included', function (string $stored, string $typed, string $prefix) {
    // Given a product whose name holds a ZWNJ or ZWJ word
    $product = searchProduct(['name' => $stored]);

    // When the typed words are tokenised in PHP and matched the way the search will (whole words, last token a prefix)
    $whole = SearchQuery::fromString($typed);
    $partial = SearchQuery::fromString($prefix);

    // Then the whole word and the prefix that ends after the joiner both find it
    expect(searchVectorHits($whole->phrase()))->toBe([$product->id])
        ->and(searchVectorHits('', $partial->prefixToken()))->toBe([$product->id]);
})->with([
    'persian zwnj' => ["می\u{200C}خواهم", "می\u{200C}خواهم", "می\u{200C}خ"],
    'bengali zwj' => ["ক্ষ\u{200D}ত্র", "ক্ষ\u{200D}ত্র", "ক্ষ\u{200D}ত"],
]);

it('lower-cases the way PHP does for Greek final sigma and Turkish dotted I', function (string $typed) {
    // Given text whose lower case depends on the Unicode rules
    // When PHP tokenises it and PostgreSQL folds it
    $php = SearchQuery::fromString($typed)->phrase();

    // Then both give the same word
    expect($php)->toBe(searchFold($typed));
})->with(['ΟΔΟΣ', "\u{0130}stanbul", 'ΣΑΣ ΟΣΟΣ']);

it('keeps unaccent in schema public, where launchpad_fold calls it', function () {
    // Given the migrated database
    // When the extension's schema is read
    $schema = DB::selectOne("select n.nspname from pg_extension e join pg_namespace n on n.oid = e.extnamespace where e.extname = 'unaccent'")->nspname;

    // Then it is public
    expect($schema)->toBe('public');
});

it('rolls the search columns back to a plain nullable vector and back-fills them again', function () {
    // Given a product with searchable text and the second search migration
    $product = searchProduct(['name' => 'Roundtrip', 'description' => 'alpha beta']);
    $migration = require database_path('migrations/2026_10_21_000013_add_search_columns_to_products_table.php');
    $column = fn (string $name) => DB::table('information_schema.columns')->where('table_name', 'products')->where('column_name', $name)->first(['is_generated', 'is_nullable']);
    $indexes = fn () => DB::table('pg_indexes')->where('tablename', 'products')->whereIn('indexname', ['products_search_vector_gin', 'products_name_search_trgm_gin'])->count();

    // When it is rolled back
    $migration->down();

    // Then the vector is a plain nullable column (empty), name_search and both indexes are gone
    expect($column('search_vector')->is_generated)->toBe('NEVER')->and($column('search_vector')->is_nullable)->toBe('YES')
        ->and($column('name_search'))->toBeNull()->and($indexes())->toBe(0)
        ->and(DB::table('products')->where('id', $product->id)->value('search_vector'))->toBeNull();

    // When it runs again
    $migration->up();

    // Then every existing row is back-filled and both indexes are back
    expect($column('search_vector')->is_generated)->toBe('ALWAYS')->and($indexes())->toBe(2)
        ->and(DB::table('products')->where('id', $product->id)->value('name_search'))->toBe('roundtrip')
        ->and(searchVectorHits('alpha'))->toBe([$product->id]);
});
