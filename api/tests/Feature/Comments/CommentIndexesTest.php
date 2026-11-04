<?php

use App\Actions\Comment\CommentTree;
use App\Enums\CommentSort;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/CommentFixtures.php';

/** A product with 400 top-level comments, each with a few replies, on an analysed table. */
function commentBulkRows(): Product
{
    $product = commentable();
    $author = User::factory()->create();
    $rows = [];
    $replyRows = [];
    for ($i = 0; $i < 400; $i++) {
        $id = (string) Str::ulid();
        $at = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC')->addMinutes($i);
        $rows[] = ['id' => $id, 'product_id' => $product->id, 'user_id' => $author->id, 'body' => "Comment {$i}", 'replies_count' => $i % 5, 'created_at' => $at, 'updated_at' => $at];
        for ($r = 0; $r < $i % 5; $r++) {
            $replyRows[] = ['id' => (string) Str::ulid(), 'product_id' => $product->id, 'user_id' => $author->id, 'parent_id' => $id, 'body' => 'Reply', 'replies_count' => 0, 'created_at' => $at->addSeconds($r + 1), 'updated_at' => $at];
        }
    }
    DB::table('comments')->insert($rows);
    DB::table('comments')->insert($replyRows);
    DB::statement('ANALYZE comments');

    return $product;
}

it('serves each real comment query from its partial index without a sort', function (string $name) {
    // Given an analysed table with plenty of rows and the application's real queries (captured with bindings)
    $product = commentBulkRows();
    $tree = app(CommentTree::class);
    $top = Comment::query()->whereNull('parent_id')->where('product_id', $product->id)->orderBy('id')->first();

    $sql = match ($name) {
        'top' => commentCaptureSql(fn () => $tree->topLevel($product, CommentSort::Top, 20)->items())[0],
        'newest' => commentCaptureSql(fn () => $tree->topLevel($product, CommentSort::Newest, 20)->items())[0],
        'replies' => commentCaptureSql(fn () => $tree->replies($top, 20)->items())[0],
        'embedded replies' => collect(commentCaptureSql(fn () => $tree->embedReplies($tree->topLevel($product, CommentSort::Newest, 20)->items())))->first(fn (string $q) => str_contains($q, 'row_number')),
    };

    // When the plan is read with sequential scans, bitmap scans and sorts disabled
    $plan = commentPlan($sql);

    // Then the matching index is used and nothing is sorted by hand (the window query sorts per partition, so it is exempt)
    $index = ['top' => 'comments_product_top_index', 'newest' => 'comments_product_newest_index', 'replies' => 'comments_parent_index', 'embedded replies' => 'comments_parent_index'][$name];
    expect($plan)->toContain($index);
    if ($name !== 'embedded replies') {
        expect($plan)->not->toContain('Sort Key');
    }
})->with(['top', 'newest', 'replies', 'embedded replies']);

it('serves the second page (a real cursor) of each list from its index without a sort', function (string $name) {
    // Given an analysed table and the first page of the real query
    $product = commentBulkRows();
    $tree = app(CommentTree::class);
    $top = Comment::query()->whereNull('parent_id')->where('product_id', $product->id)->where('replies_count', 4)->orderBy('id')->first();
    $first = match ($name) {
        'top' => $tree->topLevel($product, CommentSort::Top, 20),
        'newest' => $tree->topLevel($product, CommentSort::Newest, 20),
        'replies' => $tree->replies($top, 2),
    };
    $cursor = $first->nextCursor()->encode();

    // When the second page is run through the application (the cursor arrives as ?cursor=, as from links.next) and its SQL captured
    $sqls = commentCaptureSql(function () use ($tree, $product, $top, $name, $cursor) {
        request()->query->set('cursor', $cursor);
        match ($name) {
            'top' => $tree->topLevel($product, CommentSort::Top, 20)->items(),
            'newest' => $tree->topLevel($product, CommentSort::Newest, 20)->items(),
            'replies' => $tree->replies($top, 2)->items(),
        };
    });
    $sql = collect($sqls)->first(fn (string $q) => str_contains($q, 'from "comments"') && str_contains($q, 'limit'));

    // Then the cursor condition is in the SQL, the matching index serves it and nothing is sorted by hand
    expect($sql)->toContain((string) $first->nextCursor()->parameter('id'));
    $plan = commentPlan($sql);
    $index = ['top' => 'comments_product_top_index', 'newest' => 'comments_product_newest_index', 'replies' => 'comments_parent_index'][$name];
    expect($plan)->toContain($index)->not->toContain('Sort Key');
    expect(strtolower($sql))->toContain($name === 'top' ? 'replies_count' : 'created_at');
})->with(['top', 'newest', 'replies']);
