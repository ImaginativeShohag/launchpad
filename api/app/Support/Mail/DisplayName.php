<?php

namespace App\Support\Mail;

use App\Support\CleanText;

/** A product name as it may appear in a subject, a mail body or a notification param: cleaned of control and invisible characters, at most 120 characters. */
final class DisplayName
{
    public const MAX = 120;

    public static function of(mixed $name): string
    {
        $clean = CleanText::clean(is_string($name) ? $name : '');

        return is_string($clean) ? mb_substr($clean, 0, self::MAX) : '';
    }
}
