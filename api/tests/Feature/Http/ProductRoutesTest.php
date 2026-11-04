<?php

use Illuminate\Support\Facades\Route;

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware */
function routesMiddleware(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && ! str_contains($route->uri(), '_'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))
            ->mapWithKeys(fn ($m) => [$m.' '.substr($route->uri(), 7) => $route->gatherMiddleware()]))
        ->all();
}

it('puts the right limiter on every product write route', function () {
    // Given the registered routes
    $routes = routesMiddleware();

    // When their middleware is inspected
    // Then each write route carries exactly the limiter the spec names
    $expected = [
        'POST products' => 'product-write', 'PATCH products/{product}' => 'product-write', 'DELETE products/{product}' => 'product-write',
        'PUT products/{product}/launch' => 'product-write',
        'PUT products/{product}/vote' => 'vote', 'DELETE products/{product}/vote' => 'vote',
        'POST products/{product}/view' => 'view',
        'POST me/products/{product}/exports' => 'export-start',
        'POST products/{product}/submit' => 'product-submit', 'POST products/{product}/withdraw' => 'product-submit',
        'POST products/{product}/logo' => 'product-media', 'POST products/{product}/screenshots' => 'product-media',
        'DELETE products/{product}/logo' => 'product-write', 'PUT products/{product}/screenshots/order' => 'product-write',
        'DELETE products/{product}/screenshots/{screenshot}' => 'product-write',
        'POST products/{product}/comments' => 'comment-create',
        'PATCH comments/{comment}' => 'comment-modify', 'DELETE comments/{comment}' => 'comment-modify',
        'PUT users/{user}/follow' => 'follow', 'DELETE users/{user}/follow' => 'follow',
        'PUT topics/{topic}/follow' => 'follow', 'DELETE topics/{topic}/follow' => 'follow',
        'POST products/{product}/report' => 'report', 'POST comments/{comment}/report' => 'report',
        'POST me/notifications/read' => 'notifications-read',
        'PATCH me/email-preferences' => 'email-prefs', 'POST email/unsubscribe' => 'unsubscribe',
        'POST staff/products/{product}/review' => 'admin-write', 'POST staff/products/{product}/take-down' => 'admin-write',
        'POST staff/products/{product}/restore' => 'admin-write',
        'POST staff/reports/{type}/{id}/resolve' => 'admin-write',
        'POST staff/risk-signals/{signal}/review' => 'admin-write', 'POST staff/votes/{vote}/void' => 'admin-write',
        'POST staff/risk/members/{member}/void-votes' => 'admin-write', 'POST staff/risk/members/{member}/suspend' => 'admin-write',
        'POST admin/users/{user}/suspend' => 'admin-write', 'POST admin/users/{user}/restore' => 'admin-write',
        'POST admin/topics' => 'admin-write', 'PATCH admin/topics/{topic}' => 'admin-write', 'DELETE admin/topics/{topic}' => 'admin-write',
    ];

    foreach ($expected as $route => $limiter) {
        expect($routes)->toHaveKey($route);
        $named = collect($routes[$route])->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->values()->all();
        expect($named)->toBe(['throttle:'.$limiter]);
    }
});

it('leaves no write route under products, comments, staff, admin or me without a named limiter below the api bucket', function () {
    // Given the registered routes
    // When every non-GET product, staff, admin and me route is collected
    $unthrottled = collect(routesMiddleware())
        ->filter(fn ($middleware, $key) => ! str_starts_with($key, 'GET ') && preg_match('~^\w+ (products|comments|users/|topics/|staff/|admin/|me/)~', $key) === 1)
        ->reject(fn ($middleware) => collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api'))
        ->keys()->all();

    // Then none lacks one
    expect($unthrottled)->toBe([]);
});

it('requires sign-in, an active account and a confirmed email on every founder, staff and admin route, and none on the public reads', function () {
    // Given the registered routes
    $routes = routesMiddleware();

    // When their middleware is inspected
    // Then every founder, staff and admin route carries the three guards
    $protected = collect($routes)->keys()
        ->filter(fn ($key) => preg_match('~^\w+ (staff/|admin/|me/products|products(/\{product\}.*)?$)~', $key) === 1 || in_array($key, ['PATCH comments/{comment}', 'DELETE comments/{comment}', 'PUT users/{user}/follow', 'DELETE users/{user}/follow', 'PUT topics/{topic}/follow', 'DELETE topics/{topic}/follow', 'POST comments/{comment}/report', 'POST products/{product}/report', 'GET me/feed', 'GET me/follows/users', 'GET me/follows/topics'], true))
        ->all();
    expect($protected)->toContain('POST products', 'GET me/products', 'GET me/products/{product}', 'GET staff/review-queue', 'GET staff/products/{product}', 'GET staff/reports', 'GET staff/reports/history', 'GET staff/risk-signals', 'GET staff/risk-signals/{signal}', 'GET staff/risk/members', 'GET staff/risk/members/{member}', 'GET staff/products/{product}/vote-integrity', 'GET staff/products/{product}/votes', 'POST staff/risk-signals/{signal}/review', 'POST staff/votes/{vote}/void', 'POST staff/risk/members/{member}/void-votes', 'POST staff/risk/members/{member}/suspend', 'GET staff/reports/{type}/{id}', 'POST staff/reports/{type}/{id}/resolve', 'POST admin/users/{user}/suspend', 'POST admin/users/{user}/restore', 'PUT products/{product}/launch', 'GET admin/audit-logs', 'GET admin/users', 'GET staff/products', 'POST products/{product}/comments', 'PATCH comments/{comment}', 'DELETE comments/{comment}', 'PUT users/{user}/follow', 'PUT topics/{topic}/follow', 'POST comments/{comment}/report', 'POST products/{product}/report', 'GET me/feed', 'GET me/follows/users', 'GET me/follows/topics', 'GET me/products/{product}/dashboard', 'GET me/products/{product}/dashboard/series', 'GET me/products/{product}/dashboard/rank', 'GET me/products/{product}/dashboard/referrers', 'GET staff/products/{product}/dashboard', 'GET staff/products/{product}/dashboard/series', 'GET staff/products/{product}/dashboard/rank', 'GET staff/products/{product}/dashboard/referrers', 'POST me/products/{product}/exports', 'GET me/products/{product}/exports', 'GET me/products/{product}/exports/{export}');
    // (the view beacon matches the pattern but is public: it is asserted below)
    $protected = array_values(array_diff($protected, ['POST products/{product}/view']));
    foreach ($protected as $route) {
        expect($routes[$route])->toContain('auth:sanctum', 'active', 'verified.api');
    }
    expect($routes['POST products/{product}/view'])->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api')->toContain('throttle:view');

    // And the public reads carry none of them (today is deliberately not in SecurityHeaders' no-store prefixes)
    foreach (['GET products/{slug}', 'GET users/{user}/products', 'GET topics', 'GET config', 'GET today', 'GET days/{date}', 'GET leaderboards', 'GET search', 'GET products/{slug}/comments', 'GET comments/{comment}/replies', 'GET users/{user}'] as $route) {
        expect($routes[$route])->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api');
    }
});
