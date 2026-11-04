<?php

namespace App\Enums;

enum CommentSort: string
{
    /** Most replies first (there are no comment votes, so "top" means "most discussed"). */
    case Top = 'top';
    case Newest = 'newest';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $sort) => $sort->value, self::cases());
    }
}
