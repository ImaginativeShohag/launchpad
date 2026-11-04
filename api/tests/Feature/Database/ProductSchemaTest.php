<?php

use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function productRow(User $maker, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(), 'maker_id' => $maker->id, 'slug' => 'my-product',
        'name' => 'My Product', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

it('stores a product with a ULID id and the documented defaults', function () {
    // Given a product created by the factory
    $product = Product::factory()->create();

    // When it is reloaded
    $fresh = Product::query()->findOrFail($product->id);

    // Then the id is a ULID and the counters start at their defaults
    expect(Str::isUlid($fresh->id))->toBeTrue();
    expect($fresh->status->value)->toBe('draft');
    expect($fresh->votes_count)->toBe(0)->and($fresh->comments_count)->toBe(0)->and($fresh->hot_score)->toBe(0.0);
});

it('refuses an unknown status', function () {
    // Given a row with a bogus status
    $maker = User::factory()->create();

    // When inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('products')->insert(productRow($maker, ['status' => 'bogus'])));

    // Then the CHECK constraint refuses it
    expect($insert)->toThrow(QueryException::class, 'products_status_check');
});

it('refuses malformed slugs at the database level', function (string $slug) {
    // Given a row with a bad slug
    $maker = User::factory()->create();

    // When inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('products')->insert(productRow($maker, ['slug' => $slug])));

    // Then the format CHECK refuses it
    expect($insert)->toThrow(QueryException::class, 'products_slug_format');
})->with(['uppercase' => 'My-Product', 'leading hyphen' => '-abc', 'trailing hyphen' => 'abc-', 'double hyphen' => 'a--b', 'too short' => 'ab', 'space' => 'a b']);

it('refuses a slug longer than 80 characters (the varchar(80) column rejects it before the CHECK)', function () {
    // Given a row with an 81 character slug
    $maker = User::factory()->create();

    // When inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('products')->insert(productRow($maker, ['slug' => str_repeat('a', 81)])));

    // Then the database refuses it
    expect($insert)->toThrow(QueryException::class, 'value too long');
});

it('keeps slugs unique, soft-deleted rows included', function () {
    // Given a soft-deleted product
    Product::factory()->create(['slug' => 'taken-slug', 'deleted_at' => now()]);

    // When another product uses the same slug
    $second = fn () => DB::transaction(fn () => Product::factory()->create(['slug' => 'taken-slug']));

    // Then the unique index refuses it
    expect($second)->toThrow(QueryException::class, 'products_slug_unique');
});

it('only allows a launch date in the scheduled, live and taken_down states', function () {
    // Given a maker
    $maker = User::factory()->create();

    // When a draft has a launch date, and a scheduled product has none
    $draft = fn () => DB::transaction(fn () => DB::table('products')->insert(productRow($maker, ['launch_date' => '2030-01-01'])));
    $scheduled = fn () => DB::transaction(fn () => DB::table('products')->insert(productRow($maker, ['status' => 'scheduled'])));

    // Then both CHECK constraints refuse the row
    expect($draft)->toThrow(QueryException::class, 'products_launch_date_state');
    expect($scheduled)->toThrow(QueryException::class, 'products_scheduled_needs_date');
});

it('refuses a pending product without a submitted_at', function () {
    // Given a maker
    $maker = User::factory()->create();

    // When a pending product is inserted without the submission time
    $pending = fn () => DB::transaction(fn () => DB::table('products')->insert(productRow($maker, ['status' => 'pending'])));

    // Then the CHECK constraint refuses it
    expect($pending)->toThrow(QueryException::class, 'products_pending_needs_submitted_at');
});

it('creates the indexes the keyset orderings and the cap lookup rely on', function () {
    // Given the migrated schema
    // When the index names are read
    $names = DB::table('pg_indexes')->where('tablename', 'products')->pluck('indexname')->all();

    // Then each ordering has its matching index
    expect($names)->toContain(
        'products_status_launch_date_index',
        'products_launch_date_hot_score_index',
        'products_maker_id_launch_date_id_index',
        'products_status_submitted_at_id_index',
        'products_status_launched_at_id_index',
        'products_maker_id_created_at_id_index',
        'products_maker_id_launched_at_id_index',
    );
});

it('refuses website as a product link kind and duplicate positions', function () {
    // Given a product with one link
    $product = Product::factory()->create();
    $link = fn (array $o) => array_merge(['id' => (string) Str::ulid(), 'product_id' => $product->id, 'kind' => 'github',
        'url' => 'https://github.com/x/y', 'position' => 0, 'created_at' => now(), 'updated_at' => now()], $o);
    DB::table('product_links')->insert($link([]));

    // When a website link, and a second link at the same position, are inserted
    $website = fn () => DB::transaction(fn () => DB::table('product_links')->insert($link(['kind' => 'website', 'position' => 1])));
    $duplicate = fn () => DB::transaction(fn () => DB::table('product_links')->insert($link([])));

    // Then the CHECK and the unique index refuse them
    expect($website)->toThrow(QueryException::class, 'product_links_kind_check');
    expect($duplicate)->toThrow(QueryException::class, 'product_links_product_id_position_unique');
});

it('refuses duplicate screenshot positions', function () {
    // Given a product with a screenshot at position 0
    $product = Product::factory()->create();
    $shot = fn () => ['id' => (string) Str::ulid(), 'product_id' => $product->id, 'path' => 'p/x.webp', 'position' => 0, 'created_at' => now()];
    DB::table('product_screenshots')->insert($shot());

    // When another is inserted at the same position
    $insert = fn () => DB::transaction(fn () => DB::table('product_screenshots')->insert($shot()));

    // Then the unique index refuses it
    expect($insert)->toThrow(QueryException::class, 'product_screenshots_product_id_position_unique');
});

it('protects a topic that has products from deletion', function () {
    // Given a topic attached to a product
    $topic = Topic::factory()->create();
    $product = Product::factory()->create();
    DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);

    // When the topic is deleted
    $delete = fn () => DB::transaction(fn () => DB::table('topics')->where('id', $topic->id)->delete());

    // Then the foreign key (no cascade) refuses it
    expect($delete)->toThrow(QueryException::class, 'product_topics_topic_id_foreign');
});

it('refuses a malformed topic slug', function () {
    // Given a row with an uppercase slug
    $insert = fn () => DB::transaction(fn () => DB::table('topics')->insert([
        'id' => (string) Str::ulid(), 'slug' => 'Bad Slug', 'name' => 'Bad', 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]));

    // When inserted, Then the CHECK refuses it
    expect($insert)->toThrow(QueryException::class, 'topics_slug_format');
});

it('keeps product_reviews append-only, TRUNCATE included, and requires a note unless approved', function () {
    // Given a review row
    $product = Product::factory()->create();
    $reviewer = User::factory()->create();
    $id = (string) Str::ulid();
    DB::table('product_reviews')->insert(['id' => $id, 'product_id' => $product->id, 'reviewer_id' => $reviewer->id,
        'decision' => 'approved', 'note' => null, 'created_at' => now()]);

    // When it is updated, deleted or the table truncated
    $update = fn () => DB::transaction(fn () => DB::table('product_reviews')->where('id', $id)->update(['note' => 'x']));
    $delete = fn () => DB::transaction(fn () => DB::table('product_reviews')->where('id', $id)->delete());
    $truncate = fn () => DB::transaction(fn () => DB::table('product_reviews')->truncate());

    // Then the triggers refuse all three
    expect($update)->toThrow(QueryException::class, 'product_reviews is append-only');
    expect($delete)->toThrow(QueryException::class, 'product_reviews is append-only');
    expect($truncate)->toThrow(QueryException::class, 'product_reviews is append-only');

    // And a rejection without a note is refused by its CHECK
    $noNote = fn () => DB::transaction(fn () => DB::table('product_reviews')->insert([
        'id' => (string) Str::ulid(), 'product_id' => $product->id, 'reviewer_id' => $reviewer->id,
        'decision' => 'rejected', 'note' => '  ', 'created_at' => now(),
    ]));
    expect($noNote)->toThrow(QueryException::class, 'product_reviews_note_required');
});

it('has one immutability function and all three triggers on product_reviews', function () {
    // Given the migrated schema
    // When the catalogue is inspected
    $functions = DB::table('pg_proc')->where('proname', 'product_reviews_immutable')->count();
    $triggers = DB::table('pg_trigger')->where('tgrelid', DB::raw("'product_reviews'::regclass"))
        ->where('tgisinternal', false)->pluck('tgname')->sort()->values()->all();

    // Then the function exists once and the triggers are attached
    expect($functions)->toBe(1);
    expect($triggers)->toBe(['product_reviews_no_delete', 'product_reviews_no_truncate', 'product_reviews_no_update']);
});

it('keeps review history when a product is soft-deleted (plain foreign keys, no cascade)', function () {
    // Given a product with a review
    $product = Product::factory()->create();
    $reviewer = User::factory()->create();
    DB::table('product_reviews')->insert(['id' => (string) Str::ulid(), 'product_id' => $product->id,
        'reviewer_id' => $reviewer->id, 'decision' => 'approved', 'created_at' => now()]);

    // When the product is soft-deleted
    $product->delete();

    // Then the review stays, and a hard delete is refused by the FK
    expect(DB::table('product_reviews')->count())->toBe(1);
    $hard = fn () => DB::transaction(fn () => DB::table('products')->where('id', $product->id)->delete());
    expect($hard)->toThrow(QueryException::class, 'product_reviews_product_id_foreign');
});

it('stores products.submitted_at with microsecond precision, because review decisions compare it as a token', function () {
    // Given the migrated schema
    // When the column's precision is read
    $precision = DB::table('information_schema.columns')
        ->where('table_name', 'products')->where('column_name', 'submitted_at')->value('datetime_precision');

    // Then it keeps six fractional digits
    expect((int) $precision)->toBe(6);
});
