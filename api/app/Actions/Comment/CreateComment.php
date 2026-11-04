<?php

namespace App\Actions\Comment;

use App\Actions\Comment\Concerns\LocksCommentableProduct;
use App\Events\CommentCreated;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateComment
{
    use LocksCommentableProduct;

    /**
     * Writes a comment, or a reply to any comment of the product (one level: the reply attaches to the top-level
     * ancestor and remembers who was answered). Lock order: product row, the top-level comment, the answered reply,
     * then the author FOR SHARE. The product lock serialises every comment write of the product, so the two relative
     * counter updates cannot drift.
     *
     * @return Comment with `author`, `replyTo` and `product` loaded
     *
     * @throws ModelNotFoundException the uniform 404: hidden product, unusable `parent_id`
     * @throws AuthorizationException when the author was suspended or deleted since the request was authorised
     */
    public function handle(User $author, string $productId, string $body, ?string $parentId): Comment
    {
        $lockedProductId = $productId;
        $comment = DB::transaction(function () use ($author, $productId, $body, $parentId, &$lockedProductId): Comment {
            $product = $this->lockPublicProduct($productId);
            $lockedProductId = (string) $product->getKey();
            [$top, $replyToUserId] = $this->lockThread($product, $parentId);

            // Both accounts the insert references are locked FOR SHARE in id order before it (the foreign keys would
            // otherwise take their own FOR KEY SHARE one by one). Only the author must be available; an answered
            // account that was deleted meanwhile is simply not remembered (its comments are masked anyway).
            $states = $this->lockUsersShared($author, $replyToUserId === null ? [] : [$replyToUserId]);
            if ($replyToUserId !== null && (! isset($states[$replyToUserId]) || $states[$replyToUserId]->deleted_at !== null)) {
                $replyToUserId = null;
            }

            $now = now();
            $comment = new Comment;
            $comment->forceFill([
                'product_id' => $product->getKey(),
                'user_id' => $author->getKey(),
                'parent_id' => $top?->getKey(),
                'reply_to_user_id' => $replyToUserId,
                'body' => $body,
                'edited_at' => null,
                'removed_at' => null,
                'removed_by' => null,
                'removed_reason' => null,
                'replies_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ])->save();

            // Relative updates, not Eloquent increment() (which would also touch updated_at).
            DB::table('products')->where('id', $product->getKey())->update(['comments_count' => DB::raw('comments_count + 1')]);

            if ($top !== null) {
                DB::table('comments')->where('id', $top->getKey())->update(['replies_count' => DB::raw('replies_count + 1')]);
            }

            return $comment;
        });

        // After the transaction, so a failing listener (the event raises from the commit) cannot fail a request whose data is saved.
        try {
            CommentCreated::dispatch((string) $comment->getKey(), $lockedProductId, (string) $author->getKey());
        } catch (Throwable $e) {
            report($e);
        }

        return $comment->load(['author', 'replyTo', 'product']);
    }

    /**
     * Resolves and locks the thread an answer belongs to.
     *
     * @return array{0: ?Comment, 1: ?string} the locked top-level comment (null for a new top-level comment) and the reply_to user id
     *
     * @throws ModelNotFoundException
     */
    private function lockThread(Product $product, ?string $parentId): array
    {
        if ($parentId === null) {
            return [null, null];
        }

        if (! Str::isUlid($parentId)) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        // A plain read to learn the top-level ancestor before locking (a comment never changes product or parent).
        $answered = $this->answerable($product, $parentId);
        $isReply = $answered->parent_id !== null;
        $topId = $isReply ? $answered->parent_id : $answered->id;

        $top = Comment::query()->whereKey($topId)->lockForUpdate()->first();

        if ($top === null) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        // Answering the top-level comment itself needs it to be usable; a removed top-level comment can still carry
        // replies, and one of its visible replies can be answered.
        if ($isReply) {
            Comment::query()->whereKey($answered->id)->lockForUpdate()->first();
        }

        // Re-check on the locked rows: either may have been removed or deleted while we waited.
        $answered = $this->answerable($product, $parentId);

        return [$top, $isReply ? $answered->user_id : null];
    }

    /**
     * The answered comment if it is of this product, neither removed nor deleted, and its author's account is alive.
     *
     * @throws ModelNotFoundException
     */
    private function answerable(Product $product, string $commentId): Comment
    {
        $comment = Comment::query()
            ->whereKey($commentId)
            ->where('product_id', $product->getKey())
            ->counted()
            ->whereAuthorAlive()
            ->first();

        return $comment ?? throw (new ModelNotFoundException)->setModel(Comment::class);
    }
}
