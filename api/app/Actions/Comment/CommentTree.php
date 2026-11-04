<?php

namespace App\Actions\Comment;

use App\Enums\CommentSort;
use App\Models\Comment;
use App\Models\Product;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The public reads of a product's comments. A deleted account's comments count as deleted until the clean-up job has
 * run (the read masks them through an anti-join on `users.deleted_at`), so nothing personal is public in the meantime.
 *
 * Known drift: those ghost-authored comments and replies are still counted in `products.comments_count` and in the
 * parent's `replies_count`, so the counters over-state what the reads show until the deleted-member job (Phase C) or the
 * DeleteAccount hook (Phase B) corrects them. Phase B/C must pin that with a test.
 */
class CommentTree
{
    /**
     * Top-level comments. A removed, deleted or ghost-authored one is listed only while it still has replies (as a
     * placeholder the resource masks). Matches `comments_product_top_index` and `comments_product_newest_index`.
     *
     * @return CursorPaginator<int, Comment> with `author` and `replyTo` loaded
     */
    public function topLevel(Product $product, CommentSort $sort, int $perPage): CursorPaginator
    {
        // No join and unqualified order columns: Laravel writes the cursor keys exactly as the orderBy columns, and the
        // keys must be the bare names KeysetCursor validates. A deleted author is an anti-join on the users key.
        $query = Comment::query()
            ->where('product_id', $product->getKey())
            ->whereNull('parent_id')
            ->where(function (Builder $visible): void {
                $visible->where(function (Builder $own): void {
                    $own->counted()->whereAuthorAlive();
                })->orWhere('replies_count', '>', 0);
            })
            ->with(['author', 'replyTo']);

        if ($sort === CommentSort::Top) {
            $query->orderByDesc('replies_count');
        }

        // The links carry the sort and the page size, so a client can follow `links.next` as it is (a `top` cursor on a
        // `newest` request would be refused).
        return $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($perPage)->appends(['sort' => $sort->value, 'per_page' => $perPage]);
    }

    /**
     * Sets the relation `replies` on each top-level comment to its first `$perThread` visible replies (oldest first),
     * with one query for the whole page. A removed, deleted or ghost-authored reply never appears.
     *
     * @param  iterable<Comment>  $tops
     */
    public function embedReplies(iterable $tops, int $perThread = 3): void
    {
        $byId = [];
        foreach ($tops as $top) {
            $byId[$top->getKey()] = $top;
        }

        if ($byId === []) {
            return;
        }

        $marks = implode(',', array_fill(0, count($byId), '?'));
        $replies = Comment::fromQuery(<<<SQL
            SELECT t.* FROM (
                SELECT comments.*, row_number() OVER (PARTITION BY comments.parent_id ORDER BY comments.created_at, comments.id) AS rn
                FROM comments JOIN users u ON u.id = comments.user_id
                WHERE comments.parent_id IN ({$marks})
                    AND comments.removed_at IS NULL AND comments.deleted_at IS NULL AND u.deleted_at IS NULL
            ) t
            WHERE t.rn <= ?
            ORDER BY t.parent_id, t.created_at, t.id
        SQL, [...array_keys($byId), $perThread]);
        $replies->load(['author', 'replyTo']);

        $grouped = $replies->groupBy('parent_id');
        foreach ($byId as $id => $top) {
            $top->setRelation('replies', $grouped->get($id, new Collection)->values());
        }
    }

    /**
     * The visible replies of a top-level comment, oldest first. Matches `comments_parent_index`.
     *
     * @return CursorPaginator<int, Comment> with `author` and `replyTo` loaded
     */
    public function replies(Comment $top, int $perPage): CursorPaginator
    {
        return Comment::query()
            ->where('parent_id', $top->getKey())
            ->counted()->whereAuthorAlive()
            ->with(['author', 'replyTo'])
            ->orderBy('created_at')->orderBy('id')
            ->cursorPaginate($perPage)
            ->appends(['per_page' => $perPage]);
    }

    /** A top-level comment of a public product (maker available), with that product loaded; null for anything else. */
    public function findVisibleThread(string $commentId): ?Comment
    {
        if (! Str::isUlid($commentId)) {
            return null;
        }

        return Comment::query()
            ->whereKey($commentId)
            ->whereNull('parent_id')
            ->whereHas('product', fn (Builder $product) => $product->visible()->whereMakerAvailable())
            ->with('product')
            ->first();
    }
}
