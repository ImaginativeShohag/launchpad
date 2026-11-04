<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every client a stable, random device id: an HttpOnly cookie on the web,
 * an X-Device-Id install id from the mobile app. Only its HMAC is ever stored.
 */
class DeviceId
{
    public const COOKIE = 'lp_did';

    /** The device id the client itself sent (header first, then cookie), when it is well formed; null when it sent none. */
    public static function presented(Request $request): ?string
    {
        $valid = fn (mixed $v): ?string => is_string($v) && preg_match('/\A[A-Za-z0-9-]{16,64}\z/', $v) === 1 ? $v : null;

        return $valid($request->header('X-Device-Id')) ?? $valid($request->cookie(self::COOKIE));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::presented($request);
        $issue = $id === null;
        $id ??= Str::lower((string) Str::ulid());

        $request->attributes->set('device_id', $id);
        $response = $next($request);

        if ($issue) {
            $response->headers->setCookie(new Cookie(
                name: self::COOKIE,
                value: $id,
                expire: now()->addYears(2),
                path: '/',
                secure: (bool) (config('session.secure') ?? $request->isSecure()),
                httpOnly: true,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
