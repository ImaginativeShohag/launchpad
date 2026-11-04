<?php

namespace App\Actions\Comment\Concerns;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Lookup and locks shared by edit and delete: the product row, the top-level parent (if a reply), then the comment. */
trait LocksOwnComment
{
    use LocksCommentableProduct;

    /**
     * Must run inside the caller's transaction. A comment never changes product, author or parent, so those are read
     * plainly first; everything that can change (removed, deleted, the text) is judged by the caller on the locked row.
     *
     * @return array{0: Comment, 1: ?Comment} the locked comment and its locked top-level parent (null for a top-level comment)
     *
     * @throws ModelNotFoundException a foreign, missing, non-ULID or hidden-product comment: all one 404
     */
    private function lockOwnComment(User $author, string $commentId): array
    {
        if (! Str::isUlid($commentId)) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        $row = DB::table('comments')->where('id', $commentId)->first(['product_id', 'user_id', 'parent_id']);

        if ($row === null || $row->user_id !== $author->getKey()) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        $this->lockPublicProduct($row->product_id);

        $parent = $row->parent_id === null ? null : Comment::query()->whereKey($row->parent_id)->lockForUpdate()->first();
        $comment = Comment::query()->whereKey($commentId)->lockForUpdate()->first();

        if ($comment === null) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        return [$comment, $parent];
    }
}
