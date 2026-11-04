<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * The only place a password is verified. Hash::check() throws a RuntimeException when the stored
 * hash belongs to another algorithm or is malformed; that must mean "wrong password", never a 500.
 */
final class PasswordCheck
{
    public static function verify(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        try {
            return Hash::check($plain, $hash);
        } catch (\RuntimeException $e) {
            // One report per distinct bad hash per hour, so a broken account cannot flood the logs.
            if (Cache::add('badhash:'.sha1($hash), 1, 3600)) {
                report($e);
            }

            return false;
        }
    }
}
