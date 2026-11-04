<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Every new authenticated route prefix must be added to this list so it is never cached. `products` mixes
        // public reads (GET /products/{slug}, cacheable by headers) with founder writes, so there only the
        // non-cacheable methods (anything but GET and HEAD) are marked; the founder reads live under `me/products`.
        // `comments`, `users` and `topics` are the same: GET /comments/{id}/replies, GET /users/{handle} and GET /topics are
        // public reads (the profile varies by viewer through OptionalViewer), their follows, edits and deletes are not.
        if ($request->is('api/v1/auth/*', 'api/v1/me', 'api/v1/me/*', 'api/v1/admin/*', 'api/v1/staff/*', 'api/v1/broadcasting/*', 'api/v1/email/*', 'docs/*')
            || ($request->is('api/v1/products', 'api/v1/products/*', 'api/v1/comments/*', 'api/v1/users/*', 'api/v1/topics', 'api/v1/topics/*') && ! $request->isMethodCacheable())) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
