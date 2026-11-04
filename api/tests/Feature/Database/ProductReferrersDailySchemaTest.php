<?php

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function viewReferrerInsert(string $productId, array $overrides = []): void
{
    DB::table('product_referrers_daily')->insert(array_merge(['product_id' => $productId, 'day' => '2026-10-21', 'source' => 'google', 'visits' => 1], $overrides));
}

function viewReferrerSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

it('has the documented columns and types on product_referrers_daily', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'product_referrers_daily')
        ->get(['column_name', 'data_type', 'is_nullable', 'character_maximum_length'])->keyBy('column_name');

    // Then they are exactly the documented ones, with the documented types
    expect($columns->keys()->all())->toEqualCanonicalizing(['product_id', 'day', 'source', 'visits']);
    expect($columns['day']->data_type)->toBe('date')->and($columns['visits']->data_type)->toBe('integer')
        ->and($columns['source']->character_maximum_length)->toBe(255)->and($columns['product_id']->character_maximum_length)->toBe(26);
    expect($columns->every(fn ($c) => $c->is_nullable === 'NO'))->toBeTrue();
});

it('has the composite primary key and no other index', function () {
    // Given the migrated schema
    // When the indexes are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'product_referrers_daily')->pluck('indexdef', 'indexname');

    // Then only the primary key exists, on (product_id, day, source)
    expect($indexes->keys()->all())->toBe(['product_referrers_daily_pkey']);
    expect($indexes['product_referrers_daily_pkey'])->toContain('(product_id, day, source)');
});

it('rejects a negative visits count with a check violation', function () {
    // Given a product
    $product = Product::factory()->live()->create();

    // When a row with visits = -1 is inserted
    $state = viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['visits' => -1]));

    // Then the check constraint refuses it
    expect($state)->toBe('23514');
    expect(viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['visits' => 0])))->toBeNull();
});

it('rejects an unknown product with a foreign key violation', function () {
    // Given no such product
    // When a row is inserted for it
    $state = viewReferrerSqlState(fn () => viewReferrerInsert('01JZZZZZZZZZZZZZZZZZZZZZZZ'));

    // Then the foreign key refuses it
    expect($state)->toBe('23503');
});

it('rejects a 256 character source before any check runs', function () {
    // Given a product
    $product = Product::factory()->live()->create();

    // When a 256 character source is inserted
    $long = viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['source' => str_repeat('a', 256)]));
    $ok = viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['source' => str_repeat('a', 255)]));

    // Then the varchar width refuses it and 255 fits
    expect($long)->toBe('22001')->and($ok)->toBeNull();
});

it('rejects two rows with the same key', function () {
    // Given a stored row
    $product = Product::factory()->live()->create();
    viewReferrerInsert($product->id);

    // When the same (product, day, source) is inserted again
    $state = viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['visits' => 5]));

    // Then the primary key refuses it, and another day or source is fine
    expect($state)->toBe('23505');
    expect(viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['day' => '2026-10-22'])))->toBeNull();
    expect(viewReferrerSqlState(fn () => viewReferrerInsert($product->id, ['source' => 'bing'])))->toBeNull();
});

it('does not cascade: a product with referrer rows cannot be hard-deleted', function () {
    // Given a product with a referrer row
    $product = Product::factory()->live()->create();
    viewReferrerInsert($product->id);

    // When the product row is deleted for real
    $state = viewReferrerSqlState(fn () => DB::table('products')->where('id', $product->id)->delete());

    // Then the foreign key refuses it
    expect($state)->toBe('23503');
});
