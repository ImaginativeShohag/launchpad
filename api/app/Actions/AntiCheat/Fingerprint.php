<?php

namespace App\Actions\AntiCheat;

/**
 * What staff may see of a stored hash: its first eight characters. Enough to tell that five votes share a device, useless
 * for re-identification. A purged (null) or malformed value has no fingerprint. Stored hashes are lowercase hex, so an uppercase string is malformed too.
 */
final class Fingerprint
{
    public static function of(?string $hash): ?string
    {
        if ($hash === null || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            return null;
        }

        return substr($hash, 0, 8);
    }
}
