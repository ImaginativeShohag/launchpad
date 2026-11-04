<?php

namespace App\Support;

/** Shows enough of an address to recognise it (first character and domain), never all of it. Short local parts show nothing. */
final class MaskEmail
{
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        $local = mb_substr($email, 0, $at);

        return (mb_strlen($local) <= 3 ? '' : mb_substr($local, 0, 1)).'***'.substr($email, $at);
    }
}
