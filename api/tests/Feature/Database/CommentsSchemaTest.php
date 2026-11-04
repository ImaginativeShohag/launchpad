<?php

use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The SQLSTATE of the exception a statement raises inside a savepoint, or null when it succeeds. */
function commentSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** @return array<string, mixed> a raw comments row that satisfies every constraint unless overridden */
function commentRow(string $productId, string $userId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(), 'product_id' => $productId, 'user_id' => $userId, 'parent_id' => null, 'reply_to_user_id' => null,
        'body' => 'A fine comment', 'replies_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

it('has the documented columns on comments', function () {
    // Given the migrated schema
    // When the column names are read
    $names = DB::table('information_schema.columns')->where('table_name', 'comments')->pluck('column_name')->all();

    // Then every documented column exists (and replies_count was added to the DBML's list)
    expect($names)->toEqualCanonicalizing([
        'id', 'product_id', 'user_id', 'parent_id', 'reply_to_user_id', 'body', 'edited_at', 'removed_at', 'removed_by',
        'removed_reason', 'replies_count', 'created_at', 'updated_at', 'deleted_at',
    ]);
});

it('uses microsecond timestamptz columns for every time column', function (string $column) {
    // Given the migrated schema
    // When the column type is read
    $type = DB::table('information_schema.columns')->where('table_name', 'comments')->where('column_name', $column)->first(['data_type', 'datetime_precision']);

    // Then it is timestamp with time zone with 6 fractional digits (created_at sits in keyset cursors)
    expect($type->data_type)->toBe('timestamp with time zone')->and((int) $type->datetime_precision)->toBe(6);
})->with(['created_at', 'updated_at', 'edited_at', 'removed_at', 'deleted_at']);

it('refuses a row that breaks a check constraint with SQLSTATE 23514', function (array $override) {
    // Given a member, a product and a row that breaks one constraint
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $reviewer = User::factory()->create();

    // When it is inserted
    $state = commentSqlState(fn () => DB::table('comments')->insert(commentRow($product->id, $user->id, array_map(
        fn ($v) => $v === '@reviewer' ? $reviewer->id : $v, $override,
    ))));

    // Then the CHECK (not the column type) refuses it
    expect($state)->toBe('23514');
})->with([
    'body 2001 chars' => [['body' => str_repeat('a', 2001)]],
    'empty body on a live row' => [['body' => '']],
    'reply_to without a parent' => [['reply_to_user_id' => '@reviewer']],
    'negative replies_count' => [['replies_count' => -1]],
    'removed_at without removed_by' => [['removed_at' => '2026-10-21 10:00:00']],
    'removed_by without removed_at' => [['removed_by' => '@reviewer']],
]);

it('accepts the edge values of the checks', function (array $override) {
    // Given a member, a product and a row at the edge of a constraint
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $reviewer = User::factory()->create();

    // When it is inserted
    $state = commentSqlState(fn () => DB::table('comments')->insert(commentRow($product->id, $user->id, array_map(
        fn ($v) => $v === '@reviewer' ? $reviewer->id : $v, $override,
    ))));

    // Then it goes in
    expect($state)->toBeNull();
})->with([
    'body 2000 chars' => [['body' => str_repeat('a', 2000)]],
    'multibyte 2000 characters' => [['body' => str_repeat('é', 2000)]],
    'empty body when deleted' => [['body' => '', 'deleted_at' => '2026-10-21 10:00:00']],
    'removed with removed_by' => [['removed_at' => '2026-10-21 10:00:00', 'removed_by' => '@reviewer']],
]);

it('lets the depth trigger accept a reply to a top-level comment and reject every other parent', function () {
    // Given a top-level comment, a reply to it, and a comment on another product
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $other = Product::factory()->live()->create();
    $top = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $user->id]);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $user->id]);
    $foreign = Comment::factory()->create(['product_id' => $other->id, 'user_id' => $user->id]);

    // When replies are inserted with different parents
    $toTop = commentSqlState(fn () => DB::table('comments')->insert(commentRow($product->id, $user->id, ['parent_id' => $top->id, 'reply_to_user_id' => $user->id])));
    $toReply = commentSqlState(fn () => DB::table('comments')->insert(commentRow($product->id, $user->id, ['parent_id' => $reply->id])));
    $toUnknown = commentSqlState(fn () => DB::table('comments')->insert(commentRow($product->id, $user->id, ['parent_id' => (string) Str::ulid()])));
    $toOtherProduct = commentSqlState(fn () => DB::table('comments')->insert(commentRow($product->id, $user->id, ['parent_id' => $foreign->id])));

    // Then only the first goes in; the others are 23514 from the trigger (an unknown parent is not the FK's 23503)
    expect([$toTop, $toReply, $toUnknown, $toOtherProduct])->toBe([null, '23514', '23514', '23514']);
});

it('creates the four indexes with the exact partial predicates and column order', function () {
    // Given the migrated schema
    // When the index definitions are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'comments')->pluck('indexdef', 'indexname');

    // Then each keyset ordering has its partial index
    expect($indexes['comments_product_top_index'])
        ->toContain('(product_id, replies_count DESC, created_at DESC, id DESC) WHERE (parent_id IS NULL)');
    expect($indexes['comments_product_newest_index'])
        ->toContain('(product_id, created_at DESC, id DESC) WHERE (parent_id IS NULL)');
    expect($indexes['comments_parent_index'])
        ->toContain('(parent_id, created_at, id) WHERE (parent_id IS NOT NULL)');
    expect($indexes['comments_user_index'])->toContain('(user_id)')->not->toContain('WHERE');
    expect($indexes['comments_reply_to_user_index'])->toContain('(reply_to_user_id) WHERE (reply_to_user_id IS NOT NULL)');
    expect($indexes['comments_removed_by_index'])->toContain('(removed_by) WHERE (removed_by IS NOT NULL)');
});

it('refuses a negative products.comments_count with 23514', function () {
    // Given a product
    $product = Product::factory()->live()->create();

    // When its counter is set below zero
    $state = commentSqlState(fn () => DB::table('products')->where('id', $product->id)->update(['comments_count' => -1]));

    // Then the CHECK refuses it
    expect($state)->toBe('23514');
});

it('has plain foreign keys that refuse unknown references with 23503', function (string $column) {
    // Given a member and a product
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $unknown = (string) Str::ulid();

    // When a row points at an unknown product, user, reply_to or remover
    $row = match ($column) {
        'product_id' => commentRow($unknown, $user->id),
        'user_id' => commentRow($product->id, $unknown),
        'removed_by' => commentRow($product->id, $user->id, ['removed_at' => now(), 'removed_by' => $unknown]),
    };
    $state = commentSqlState(fn () => DB::table('comments')->insert($row));

    // Then the foreign key refuses it
    expect($state)->toBe('23503');
})->with(['product_id', 'user_id', 'removed_by']);

it('does not cascade: a product with comments cannot be hard-deleted', function () {
    // Given a comment
    $comment = Comment::factory()->create();

    // When its product row is deleted for good
    $state = commentSqlState(fn () => DB::table('products')->where('id', $comment->product_id)->delete());

    // Then the foreign key refuses it
    expect($state)->toBe('23503');
});

it('stores a comment with a ULID id and microsecond timestamps', function () {
    // Given a comment created at a microsecond instant
    $at = now()->microsecond(123456);
    $comment = Comment::factory()->createdAt($at)->create();

    // When it is reloaded
    $fresh = Comment::query()->findOrFail($comment->id);

    // Then the id is a ULID and the microseconds survived
    expect(Str::isUlid($fresh->id))->toBeTrue()->and($fresh->created_at->micro)->toBe(123456);
});

it('lets every factory state satisfy the checks', function () {
    // Given a top-level comment
    $top = Comment::factory()->create();

    // When each state is created
    $reply = Comment::factory()->replyTo($top, User::factory()->create())->create();
    $removed = Comment::factory()->removed()->create();
    $deleted = Comment::factory()->deletedByAuthor()->create();

    // Then they all inserted and carry the intended columns
    expect($reply->parent_id)->toBe($top->id)->and($reply->product_id)->toBe($top->product_id)->and($reply->reply_to_user_id)->not->toBeNull();
    expect($removed->removed_at)->not->toBeNull()->and($removed->removed_by)->not->toBeNull()->and($removed->removed_reason)->toBe('spam');
    expect($deleted->deleted_at)->not->toBeNull()->and($deleted->body)->toBe('');
});

it('selects only comments that count with Comment::counted()', function () {
    // Given a visible, a removed and a deleted comment
    $visible = Comment::factory()->create();
    Comment::factory()->removed()->create();
    Comment::factory()->deletedByAuthor()->create();

    // When the scope is applied
    $ids = Comment::query()->counted()->pluck('id')->all();

    // Then only the visible one is left
    expect($ids)->toBe([$visible->id]);
});

it('serves the foreign-key lookups of reply_to_user_id and removed_by from their partial indexes', function (string $column, string $index) {
    // Given an analysed table where almost no row has the column set
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $target = User::factory()->create();
    $rows = [];
    for ($i = 0; $i < 300; $i++) {
        $rows[] = commentRow($product->id, $user->id, ['body' => "Row {$i}"]);
    }
    DB::table('comments')->insert($rows);
    DB::statement('ANALYZE comments');

    // When the lookup by that column is planned with sequential and bitmap scans disabled
    foreach (['enable_seqscan', 'enable_bitmapscan'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $plan = collect(DB::select("EXPLAIN SELECT id FROM comments WHERE {$column} = '{$target->id}'"))->pluck('QUERY PLAN')->implode("\n");

    // Then the partial index serves it
    expect($plan)->toContain($index);
})->with([['reply_to_user_id', 'comments_reply_to_user_index'], ['removed_by', 'comments_removed_by_index']]);

it('validates the products.comments_count check (a NOT VALID check would leave old rows unchecked)', function () {
    // Given the migrated schema
    // When the constraint is read
    $validated = DB::table('pg_constraint')->where('conname', 'products_comments_count_nonneg')->value('convalidated');

    // Then it was validated
    expect($validated)->toBeTrue();
});

it('keeps product_id, user_id and parent_id immutable: any change is 23514', function (string $column) {
    // Given a top-level comment, a reply to it, and other rows to point at
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $other = Product::factory()->live()->create();
    $top = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $user->id]);
    $otherTop = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $user->id]);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $user->id]);
    $target = $column === 'parent_id' ? $reply : $top;
    $newValue = match ($column) {
        'product_id' => $other->id,
        'user_id' => User::factory()->create()->id,
        'parent_id' => $otherTop->id,
    };

    // When the column is updated
    $state = commentSqlState(fn () => DB::table('comments')->where('id', $target->id)->update([$column => $newValue]));

    // Then the trigger refuses it
    expect($state)->toBe('23514');
})->with(['product_id', 'user_id', 'parent_id']);

it('lets other columns change and the immutable ones be rewritten with the same value', function () {
    // Given a reply
    $user = User::factory()->create();
    $top = Comment::factory()->create();
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $user->id]);

    // When the body changes, and the immutable columns are set to what they already are
    $body = commentSqlState(fn () => DB::table('comments')->where('id', $reply->id)->update(['body' => 'Edited', 'edited_at' => now()]));
    $same = commentSqlState(fn () => DB::table('comments')->where('id', $reply->id)->update(['parent_id' => $top->id, 'product_id' => $top->product_id, 'user_id' => $user->id]));

    // Then neither is refused
    expect([$body, $same])->toBe([null, null]);
});
