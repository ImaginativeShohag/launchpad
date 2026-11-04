<?php

namespace App\Actions\Comment;

use App\Models\Comment;
use Carbon\CarbonInterface;

/** The one definition of "the author may still edit": inside `community.comment_edit_minutes` of `created_at`; 0 minutes means never. */
final class EditWindow
{
    public static function isOpen(Comment $comment, int $minutes, CarbonInterface $now): bool
    {
        return $minutes > 0 && $now->lessThanOrEqualTo($comment->created_at->copy()->addMinutes($minutes));
    }
}
