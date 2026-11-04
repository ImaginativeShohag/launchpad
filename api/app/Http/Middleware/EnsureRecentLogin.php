<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts with a password prove themselves with it. Accounts without one
 * (Google-only) must have signed in within the last few minutes. A missing or
 * old last_login_at fails closed.
 *
 * The window is per ACCOUNT (users.last_login_at), not per session or token: inside the window any
 * session of a Google-only account passes, not just the one that signed in.
 */
class EnsureRecentLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->hasPassword()) {
            return $next($request);
        }

        $window = (int) config('launchpad.recent_login_minutes');

        if ($user->last_login_at === null
            || $user->last_login_at->isFuture()
            || $user->last_login_at->lt(now()->subMinutes($window))) {
            return response()->json([
                'message' => 'Please sign in again to continue.',
                'code' => 'reauth_required',
            ], 403);
        }

        return $next($request);
    }
}
