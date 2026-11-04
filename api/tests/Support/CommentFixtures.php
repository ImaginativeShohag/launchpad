<?php

use App\Models\Comment;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** A public product (live, launched yesterday), made by somebody else. */
function commentable(array $attributes = []): Product
{
    return Product::factory()->live()->create($attributes);
}

function commentPostRequest(string $productId, array $payload = []): TestResponse
{
    return test()->postJson("/api/v1/products/{$productId}/comments", $payload);
}

/** Both counters of a product next to what the rows say: [column, counted rows]. */
function productCommentCounters(Product|string $product): array
{
    $id = $product instanceof Product ? $product->id : $product;

    return [
        (int) DB::table('products')->where('id', $id)->value('comments_count'),
        DB::table('comments')->where('product_id', $id)->whereNull('removed_at')->whereNull('deleted_at')->count(),
    ];
}

/** Every counter in the database matches its rows: products.comments_count and every top-level replies_count. */
function assertCommentCountersConsistent(): void
{
    foreach (DB::table('products')->get(['id', 'comments_count']) as $product) {
        $counted = DB::table('comments')->where('product_id', $product->id)->whereNull('removed_at')->whereNull('deleted_at')->count();
        expect([$product->id => (int) $product->comments_count])->toBe([$product->id => $counted]);
    }

    foreach (DB::table('comments')->whereNull('parent_id')->get(['id', 'replies_count']) as $top) {
        $replies = DB::table('comments')->where('parent_id', $top->id)->whereNull('removed_at')->whereNull('deleted_at')->count();
        expect([$top->id => (int) $top->replies_count])->toBe([$top->id => $replies]);
    }
}

/**
 * What ResolveReport's removal will do, for tests that need staff-removed rows with consistent counters: marks the
 * comment removed and moves the two counters under no lock (single-threaded test).
 */
function staffRemoveComment(Comment $comment, string $removerId): void
{
    $comment->refresh();
    DB::table('comments')->where('id', $comment->id)->update(['removed_at' => now(), 'removed_by' => $removerId, 'removed_reason' => 'spam']);

    if ($comment->removed_at !== null || $comment->deleted_at !== null) {
        return;
    }

    DB::table('products')->where('id', $comment->product_id)->update(['comments_count' => DB::raw('comments_count - 1')]);

    if ($comment->parent_id !== null) {
        DB::table('comments')->where('id', $comment->parent_id)->update(['replies_count' => DB::raw('replies_count - 1')]);
    }
}

/** A top-level comment on `$product` with exact counters-by-hand: `$replies` is only the stored column, no reply rows are made. */
function topComment(Product $product, string $createdAt, int $replies = 0, array $attributes = []): Comment
{
    return Comment::factory()->create([
        'product_id' => $product->id,
        'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        'updated_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        'replies_count' => $replies,
        ...$attributes,
    ]);
}

/** Adds `$count` visible replies (oldest first, one second apart from `$from`) to a top-level comment and sets its counter to match. */
function addReplies(Comment $top, int $count, string $from = '2026-10-21 10:00:00', array $attributes = []): array
{
    $replies = [];
    for ($i = 0; $i < $count; $i++) {
        $at = CarbonImmutable::parse($from, 'UTC')->addSeconds($i);
        $replies[] = Comment::factory()->replyTo($top)->create([
            'created_at' => $at, 'updated_at' => $at, 'body' => "Reply {$i} to {$top->id}", ...$attributes,
        ]);
    }
    DB::table('comments')->where('id', $top->id)->update(['replies_count' => DB::raw("replies_count + {$count}")]);

    return $replies;
}

/** @return list<string> the SQL (bindings substituted) of every statement the callback ran that starts with $prefix */
function commentCaptureSql(callable $callback, string $prefix = 'select'): array
{
    $captured = [];
    DB::listen(function ($query) use (&$captured, $prefix) {
        if (str_starts_with(strtolower(ltrim($query->sql)), $prefix)) {
            $captured[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
        }
    });
    $callback();

    return $captured;
}

/** The plan of $sql with sequential scans, bitmap scans and sorts disabled, so a tiny table shows what an index CAN serve. */
function commentPlan(string $sql): string
{
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }

    return collect(DB::select('EXPLAIN '.$sql))->pluck('QUERY PLAN')->implode("\n");
}

/**
 * Follows `links.next` of a list endpoint until it ends, returning the ids in the order served.
 *
 * @return list<string>
 */
function walkCommentPages(string $url, int $guard = 100): array
{
    $ids = [];
    while ($url !== null && $guard-- > 0) {
        $page = test()->getJson($url)->assertOk();
        array_push($ids, ...array_column($page->json('data'), 'id'));
        $next = $page->json('links.next');
        $url = $next === null ? null : (string) parse_url($next, PHP_URL_PATH).'?'.parse_url($next, PHP_URL_QUERY);
    }

    return $ids;
}
