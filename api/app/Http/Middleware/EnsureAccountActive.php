<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspension or deletion takes effect on the very next request. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Put auth:sanctum first; this is only a safety net if it was forgotten.
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isAvailable()) {
            return response()->json([
                'message' => 'This account is unavailable.',
                'code' => 'account_unavailable',
            ], 403);
        }

        return $next($request);
    }
}
