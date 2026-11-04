<?php

use App\Http\Middleware\DeviceId;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureRecentLogin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // The one-click unsubscribe is POSTed by mail clients (no cookie, no CSRF token) and by the SPA from a stateful
        // origin; it is authorised by its relative signature, so it must never turn into a 419. Only this path.
        // The view beacon is sent with sendBeacon/fetch(keepalive) from every page, which cannot be relied on to carry an XSRF
        // token; it reads no session and changes no account state. Only these two paths (the method accumulates).
        $middleware->preventRequestForgery(except: ['api/v1/email/unsubscribe', 'api/v1/products/*/view']);
        $middleware->throttleApi();
        $middleware->encryptCookies(except: ['lp_did']);
        $middleware->api(append: [DeviceId::class]);
        // Global (not api-group) so unmatched-route 404s and 429s also carry the headers.
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'verified.api' => EnsureEmailVerified::class,
            'recent.login' => EnsureRecentLogin::class,
        ]);
        // Route-model binding must not run before the account guards, or a suspended or unconfirmed member would
        // see 403 for an existing id and 404 for a missing one (an existence oracle).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAccountActive::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureEmailVerified::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->dontFlash(['token', 'id_token']);

        $notFound = fn (Throwable $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Not found.'], 404)
            : null;

        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $notFound($e, $request));
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $notFound($e, $request));
        // A policy's denyAsNotFound() becomes an HttpException(404), not a NotFoundHttpException: give it the
        // same body, or a foreign product would be distinguishable from a missing one.
        $exceptions->render(fn (HttpException $e, Request $request) => $e->getStatusCode() === 404 ? $notFound($e, $request) : null);
    })
    ->create();
