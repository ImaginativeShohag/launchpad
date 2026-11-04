<?php

namespace App\Actions\Comment;

use App\Actions\Comment\Concerns\LocksOwnComment;
use App\Actions\Report\CloseReports;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeleteComment
{
    use LocksOwnComment;

    public function __construct(private readonly CloseReports $reports) {}

    /**
     * The author deletes their comment: the body is wiped and the row stays (replies keep their parent). Idempotent:
     * an already deleted comment answers with the placeholder and changes nothing. Same lock order as the edit.
     *
     * @return Comment the placeholder, with `author`, `replyTo` and `product` loaded
     *
     * @throws ModelNotFoundException the uniform 404: foreign, missing, staff-removed or hidden-product comment
     * @throws AuthorizationException when the author was suspended or deleted since the request was authorised
     */
    public function handle(User $author, string $commentId): Comment
    {
        return DB::transaction(function () use ($author, $commentId): Comment {
            [$comment, $parent] = $this->lockOwnComment($author, $commentId);

            // A staff-removed comment is "missing" to its author.
            if ($comment->removed_at !== null) {
                throw (new ModelNotFoundException)->setModel(Comment::class);
            }

            // Already done: nothing else runs, nothing changes.
            if ($comment->deleted_at === null) {
                // Lock order: product, comment rows, then the comment's open report rows (leaf rows), then the actor.
                $openReports = $this->reports->lockOpen('comment', (string) $comment->getKey());
                $this->assertActorAvailable($author);
                $this->apply($comment, $parent);

                // Closed by the system (no handler), so they do not count toward the reporter's reliability.
                $this->reports->close($openReports, ReportStatus::Resolved, null, 'deleted_by_author');
            }

            return $comment->load(['author', 'replyTo', 'product']);
        });
    }

    /**
     * The clean-up job's version of the delete, for a comment whose author deleted their account: the same placeholder
     * and counter logic, under the same locks (product row, top-level parent, comment, open reports), but the author is
     * not the caller and the product needs no visibility (a taken-down product's comments are erased too). A live
     * member's comment is never touched. A staff-removed comment only loses its body (it was uncounted already), and
     * keeps its removal record. No actor lock, no event, no audit. Must not run inside a transaction that holds a user
     * row: it takes a product row. Each call is its own transaction.
     *
     * @return bool true when it changed something (false: not a deleted member's comment, or already erased)
     */
    public function forDeletedMember(string $commentId): bool
    {
        if (! Str::isUlid($commentId)) {
            return false;
        }

        // The author, product and parent of a comment never change, so plain reads decide what to lock.
        $row = DB::table('comments')->where('id', $commentId)->first(['product_id', 'parent_id', 'user_id']);

        if ($row === null || ! DB::table('users')->where('id', $row->user_id)->whereNotNull('deleted_at')->exists()) {
            return false;
        }

        return DB::transaction(function () use ($commentId, $row): bool {
            if (Product::query()->withTrashed()->whereKey($row->product_id)->lockForUpdate()->first() === null) {
                return false;
            }

            $parent = $row->parent_id === null ? null : Comment::query()->whereKey($row->parent_id)->lockForUpdate()->first();
            $comment = Comment::query()->whereKey($commentId)->lockForUpdate()->first();

            if ($comment === null || $comment->deleted_at !== null) {
                return false;
            }

            $openReports = $this->reports->lockOpen('comment', $commentId);

            if ($comment->removed_at !== null) {
                $now = now();
                $comment->forceFill(['deleted_at' => $now, 'body' => '', 'updated_at' => $now])->save();
            } else {
                $this->apply($comment, $parent);
            }

            $this->reports->close($openReports, ReportStatus::Resolved, null, 'account_deleted');

            return true;
        });
    }

    /**
     * The counter and placeholder logic, for a comment and its top-level parent that the caller has locked (under
     * the product lock) and that is still counted. Kept apart so the clean-up of a deleted member's comments reuses it.
     */
    private function apply(Comment $locked, ?Comment $lockedParent): void
    {
        $now = now();
        $locked->forceFill(['deleted_at' => $now, 'body' => '', 'updated_at' => $now])->save();

        // Relative updates; a deleted top-level comment keeps its own replies_count (its replies stay counted and visible).
        DB::table('products')->where('id', $locked->product_id)->update(['comments_count' => DB::raw('comments_count - 1')]);

        if ($lockedParent !== null) {
            DB::table('comments')->where('id', $lockedParent->getKey())->update(['replies_count' => DB::raw('replies_count - 1')]);
        }
    }
}
