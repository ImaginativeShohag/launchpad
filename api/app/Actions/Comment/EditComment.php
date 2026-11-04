<?php

namespace App\Actions\Comment;

use App\Actions\Comment\Concerns\LocksOwnComment;
use App\Enums\SettingKey;
use App\Exceptions\CommunityRuleViolation;
use App\Models\Comment;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class EditComment
{
    use LocksOwnComment;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Replaces the text within the edit window. Lock order: product row, top-level parent, the comment, then the
     * author FOR SHARE. The window is judged on the locked row with a clock read after the lock wait.
     *
     * @return Comment with `author`, `replyTo` and `product` loaded
     *
     * @throws ModelNotFoundException the uniform 404: foreign, missing, removed, deleted or hidden-product comment
     * @throws CommunityRuleViolation 409 `edit_window_closed`
     * @throws AuthorizationException when the author was suspended or deleted since the request was authorised
     */
    public function handle(User $author, string $commentId, string $body): Comment
    {
        return DB::transaction(function () use ($author, $commentId, $body): Comment {
            [$comment] = $this->lockOwnComment($author, $commentId);

            // The row was read under lock: removed and deleted comments are "missing" to their author.
            if ($comment->removed_at !== null || $comment->deleted_at !== null) {
                throw (new ModelNotFoundException)->setModel(Comment::class);
            }

            $this->assertActorAvailable($author);

            // Idempotent "already done" before "too late": the same text is a quiet 200 at any time.
            if ($comment->body === $body) {
                return $comment->load(['author', 'replyTo', 'product']);
            }

            $now = now();

            if (! EditWindow::isOpen($comment, $this->settings->integer(SettingKey::CommunityCommentEditMinutes), $now)) {
                throw CommunityRuleViolation::editWindowClosed();
            }

            $comment->forceFill(['body' => $body, 'edited_at' => $now, 'updated_at' => $now])->save();

            return $comment->load(['author', 'replyTo', 'product']);
        });
    }
}
