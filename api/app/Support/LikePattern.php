<?php

namespace App\Support;

/** Builds LIKE patterns from user text: the text is lower-cased the way Postgres lower() does (simple case mapping) and its wildcards are escaped. Always bind the result and write `ESCAPE '\'`. */
final class LikePattern
{
    public static function folded(string $text): string
    {
        return mb_convert_case($text, MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /** The text with `\`, `%` and `_` escaped, so it matches itself literally. */
    public static function escape(string $text): string
    {
        return addcslashes($text, '\\%_');
    }

    public static function prefix(string $text): string
    {
        return self::escape(self::folded($text)).'%';
    }

    public static function contains(string $text): string
    {
        return '%'.self::escape(self::folded($text)).'%';
    }
}
