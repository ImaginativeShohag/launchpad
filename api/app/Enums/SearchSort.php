<?php

namespace App\Enums;

enum SearchSort: string
{
    /** Best match first (the default whenever a query is typed). */
    case Relevance = 'relevance';
    case Newest = 'newest';
    /** Most voted first (the default for a topic-only search). */
    case Votes = 'votes';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $sort) => $sort->value, self::cases());
    }
}
