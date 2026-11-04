<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Builds the per-minute `login` limiter key in one place so the limiter and the success path agree. */
final class LoginThrottle
{
    public static function minuteKey(Request $request, ?string $email = null): string
    {
        $email ??= is_string($input = $request->input('email')) ? $input : '';

        return 'login:'.$request->ip().'|'.User::normaliseEmail($email);
    }

    /** Forget the failed attempts of this ip and email. The hourly per-email bucket is deliberately kept. */
    public static function clear(Request $request, string $email): void
    {
        $key = self::minuteKey($request, $email);

        // ThrottleRequests stores a named limiter's bucket under md5(<limiter name>.<key>).
        RateLimiter::clear(md5('login'.$key));
    }
}
