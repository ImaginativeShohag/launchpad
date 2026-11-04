<?php

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function exportSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** A raw `exports` row; defaults are a legal queued row of a fresh live product. */
function exportRawRow(array $overrides = []): array
{
    $product = Product::factory()->live()->create();

    return array_merge([
        'id' => strtolower((string) Str::ulid()), 'user_id' => $product->maker_id, 'product_id' => $product->id, 'status' => 'queued',
        'file_path' => null, 'file_size' => null, 'row_counts' => null, 'error' => null, 'completed_at' => null, 'expires_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

/** The columns a `ready` row must carry. */
function exportReadyColumns(): array
{
    return ['status' => 'ready', 'file_path' => 'p/e.zip', 'file_size' => 10, 'row_counts' => '{}', 'completed_at' => now(), 'expires_at' => now()->addDay()];
}

it('has the documented columns and types', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'exports')
        ->get(['column_name', 'data_type', 'is_nullable', 'character_maximum_length', 'datetime_precision'])->keyBy('column_name');

    // Then they are exactly the documented ones
    expect($columns->keys()->all())->toEqualCanonicalizing(['id', 'user_id', 'product_id', 'status', 'file_path', 'file_size', 'row_counts', 'error', 'completed_at', 'expires_at', 'created_at', 'updated_at']);
    expect($columns['status']->character_maximum_length)->toBe(16)->and($columns['error']->character_maximum_length)->toBe(32)
        ->and($columns['file_path']->character_maximum_length)->toBe(255)->and($columns['file_size']->data_type)->toBe('integer')
        ->and($columns['row_counts']->data_type)->toBe('jsonb')->and($columns['id']->character_maximum_length)->toBe(26);
    foreach (['completed_at', 'expires_at', 'created_at', 'updated_at'] as $name) {
        expect($columns[$name]->data_type)->toBe('timestamp with time zone')->and($columns[$name]->datetime_precision)->toBe(6);
    }
    expect($columns['status']->is_nullable)->toBe('NO')->and($columns['created_at']->is_nullable)->toBe('NO')->and($columns['error']->is_nullable)->toBe('YES');
});

it('inserts the legal rows of every status', function () {
    // Given legal rows of each status (a ready row carries its file columns, error only on failed)
    $states = [
        'queued' => [], 'processing' => ['status' => 'processing'], 'ready' => exportReadyColumns(),
        'failed' => ['status' => 'failed', 'error' => 'generation_failed'], 'expired' => ['status' => 'expired'],
    ];

    // When each is inserted
    // Then every one is accepted
    foreach ($states as $overrides) {
        expect(exportSqlState(fn () => DB::table('exports')->insert(exportRawRow($overrides))))->toBeNull();
    }
});

it('rejects each illegal row with a check violation', function (array $overrides) {
    // Given an illegal row
    // When it is inserted
    $state = exportSqlState(fn () => DB::table('exports')->insert(exportRawRow($overrides)));

    // Then a CHECK refuses it
    expect($state)->toBe('23514');
})->with([
    'unknown status' => [['status' => 'bogus']],
    'ready without file_path' => [[...exportReadyColumns(), 'file_path' => null]],
    'ready without file_size' => [[...exportReadyColumns(), 'file_size' => null]],
    'ready without completed_at' => [[...exportReadyColumns(), 'completed_at' => null]],
    'ready without expires_at' => [[...exportReadyColumns(), 'expires_at' => null]],
    'ready without row_counts' => [[...exportReadyColumns(), 'row_counts' => null]],
    'negative file size' => [['file_size' => -1]],
    'file size over 5 MB' => [['file_size' => 5242881]],
    'error on a queued row' => [['error' => 'generation_failed']],
    'error on a ready row' => [[...exportReadyColumns(), 'error' => 'stalled']],
]);

it('accepts the file size bounds', function () {
    // Given sizes at both edges
    // When they are inserted
    // Then both are legal
    expect(exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['file_size' => 0]))))->toBeNull();
    expect(exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['file_size' => 5242880]))))->toBeNull();
});

it('allows one active export per product through the partial unique index', function () {
    // Given a product with a queued export
    $first = exportRawRow();
    DB::table('exports')->insert($first);
    $again = fn (array $overrides) => exportRawRow(['user_id' => $first['user_id'], 'product_id' => $first['product_id'], ...$overrides]);

    // When a second active row is inserted for the same product
    // Then queued, processing and ready are all refused (23505)
    foreach ([[], ['status' => 'processing'], exportReadyColumns()] as $overrides) {
        expect(exportSqlState(fn () => DB::table('exports')->insert($again($overrides))))->toBe('23505');
    }

    // And a failed or expired row coexists with the active one, and another product is independent
    expect(exportSqlState(fn () => DB::table('exports')->insert($again(['status' => 'failed', 'error' => 'stalled']))))->toBeNull();
    expect(exportSqlState(fn () => DB::table('exports')->insert($again(['status' => 'expired']))))->toBeNull();
    expect(exportSqlState(fn () => DB::table('exports')->insert(exportRawRow())))->toBeNull();
});

it('lets a new export in once the active one is failed or expired', function (string $final) {
    // Given a product whose only export is final
    $first = exportRawRow(['status' => $final, 'error' => $final === 'failed' ? 'generation_failed' : null]);
    DB::table('exports')->insert($first);

    // When a new queued export is inserted
    $state = exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['user_id' => $first['user_id'], 'product_id' => $first['product_id']])));

    // Then it is accepted
    expect($state)->toBeNull();
})->with(['failed', 'expired']);

it('has the four indexes with the exact column order and predicates', function () {
    // Given the migrated schema
    // When the index definitions are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'exports')->pluck('indexdef', 'indexname');

    // Then each is defined as documented
    expect($indexes['exports_user_id_created_at_index'])->toContain('USING btree (user_id, created_at)');
    expect($indexes['exports_one_active_per_product'])->toContain('CREATE UNIQUE INDEX')->toContain('(product_id)')
        ->toContain("(status)::text = ANY ((ARRAY['queued'::character varying, 'processing'::character varying, 'ready'::character varying])::text[])");
    expect($indexes['exports_status_expires_at_index'])->toContain('USING btree (status, expires_at)');
    expect($indexes['exports_product_created_index'])->toContain('USING btree (product_id, created_at DESC, id DESC)');
    expect($indexes->keys()->all())->toEqualCanonicalizing(['exports_pkey', 'exports_user_id_created_at_index', 'exports_one_active_per_product', 'exports_status_expires_at_index', 'exports_product_created_index']);
});

it('matches the predicate text RequestExport and Export::active() query with', function () {
    // Given the scope and the index
    $sql = Export::query()->active()->toRawSql();

    // When both are read
    // Then the scope lists exactly the statuses of the index predicate
    expect($sql)->toContain("\"status\" in ('queued', 'processing', 'ready')");
    expect(DB::table('pg_indexes')->where('indexname', 'exports_one_active_per_product')->value('indexdef'))->toContain("'queued'")->toContain("'processing'")->toContain("'ready'");
});

it('refuses an unknown user or product and does not cascade', function () {
    // Given a stored export
    $row = exportRawRow();
    DB::table('exports')->insert($row);

    // When rows with unknown parents are inserted, and the parents are hard-deleted
    $noUser = exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['user_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])));
    $noProduct = exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['product_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])));
    $deleteProduct = exportSqlState(fn () => DB::table('products')->where('id', $row['product_id'])->delete());
    $deleteUser = exportSqlState(fn () => DB::table('users')->where('id', $row['user_id'])->delete());

    // Then the foreign keys refuse every one of them (23503)
    expect([$noUser, $noProduct, $deleteProduct, $deleteUser])->toBe(['23503', '23503', '23503', '23503']);
});

it('rejects a 33 character error before any check runs', function () {
    // Given a failed row
    // When the error is 33 and 32 characters
    $long = exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['status' => 'failed', 'error' => str_repeat('e', 33)])));
    $ok = exportSqlState(fn () => DB::table('exports')->insert(exportRawRow(['status' => 'failed', 'error' => str_repeat('e', 32)])));

    // Then the varchar width refuses 33 (22001) and 32 fits
    expect($long)->toBe('22001')->and($ok)->toBeNull();
});

it('round-trips a row through the model with microseconds and the status enum', function () {
    // Given an export created through the factory
    $export = Export::factory()->ready()->create();

    // When it is read back
    $found = Export::query()->findOrFail($export->id);

    // Then the casts apply
    expect($found->status)->toBe(ExportStatus::Ready)->and($found->row_counts)->toBeArray()->and($found->file_size)->toBe(1234)
        ->and($found->completed_at)->not->toBeNull()->and($found->user_id)->toBe($found->product->maker_id);
    expect(Product::query()->findOrFail($found->product_id)->exports()->count())->toBe(1);
    expect($found->owner)->toBeInstanceOf(User::class);
});

it('builds factory states that satisfy every constraint', function () {
    // Given each factory state
    $states = [Export::factory(), Export::factory()->processing(), Export::factory()->ready(), Export::factory()->failed(), Export::factory()->expired(), Export::factory()->failed('too_large')];

    // When they are created
    // Then none violates a CHECK and each has the expected status
    $statuses = array_map(fn ($factory) => $factory->create()->status, $states);
    expect($statuses)->toBe([ExportStatus::Queued, ExportStatus::Processing, ExportStatus::Ready, ExportStatus::Failed, ExportStatus::Expired, ExportStatus::Failed]);
    expect(Export::query()->active()->count())->toBe(3);
});
