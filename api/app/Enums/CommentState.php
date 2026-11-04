<?php

namespace App\Enums;

/** What a comment looks like to the public: its text, a staff-removed husk, or an author-deleted husk. */
enum CommentState: string
{
    case Visible = 'visible';
    case Removed = 'removed';
    case Deleted = 'deleted';
}
