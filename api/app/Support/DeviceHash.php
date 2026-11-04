<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

/** IPs and device ids are stored only as keyed hashes, never raw. */
final class DeviceHash
{
    private const MIN_KEY_LENGTH = 32;

    public static function hmac(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = (string) config('launchpad.hmac_key');
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException('LAUNCHPAD_HMAC_KEY must be set and at least '.self::MIN_KEY_LENGTH.' characters long.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    public static function ip(Request $request): ?string
    {
        return self::hmac($request->ip());
    }

    /**
     * The keyed hash of the address for view counting and its limiter: an IPv6 address is reduced to its /64 first (one
     * subscriber owns a whole /64, so rotating the lower 64 bits must not buy a fresh cap), an IPv4-mapped IPv6 address
     * counts as its IPv4 address, IPv4 is unchanged.
     */
    public static function viewIp(Request $request): ?string
    {
        return self::hmac(self::prefix($request->ip()));
    }

    public static function prefix(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12)) ?: $ip;
        }

        return (inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)) ?: $ip).'/64';
    }

    public static function device(Request $request): ?string
    {
        $id = $request->attributes->get('device_id');

        return self::hmac(is_string($id) ? $id : null);
    }
}
