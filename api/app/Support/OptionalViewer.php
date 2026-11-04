<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is looking at a public route, when they happen to be signed in. The route never requires it: no token, a bad
 * token and an unavailable (suspended or deleted) account are all "nobody".
 *
 * Cookies: resolving the user through the sanctum guard sets none by itself. A request from a non-stateful origin
 * gets only the global lp_did cookie. A request from a Sanctum stateful origin (the SPA) runs the framework's session
 * middleware, which every API route has, so it also gets a session cookie and XSRF-TOKEN; that is accepted for now.
 */
class OptionalViewer
{
    public static function from(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User && $user->isAvailable() ? $user : null;
    }

    /**
     * The one cache policy of the viewer-dependent public reads: the answer differs by credentials, so it varies on
     * them, and a response made for a known viewer is never stored. A guest keeps Laravel's default (no-cache, private).
     *
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public static function cacheHeaders(Response $response, ?User $viewer): Response
    {
        $response->headers->set('Vary', 'Authorization, Cookie');

        if ($viewer !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
