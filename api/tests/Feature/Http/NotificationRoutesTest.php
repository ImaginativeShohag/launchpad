<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware, for the bell routes */
function notificationRoutes(): array
{
    $found = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        if (! str_starts_with($route->uri(), 'api/v1/me/notifications') || str_contains($route->uri(), '_')) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                $found[$method.' '.substr($route->uri(), 7)] = $route->gatherMiddleware();
            }
        }
    }
    ksort($found);

    return $found;
}

it('registers exactly the three bell routes', function () {
    // Given the registered routes
    // When the bell routes are listed
    // Then there are exactly the three documented ones
    expect(array_keys(notificationRoutes()))->toBe(['GET me/notifications', 'GET me/notifications/unread-count', 'POST me/notifications/read']);
});

it('puts the bell routes behind sign-in and an active account, not behind email verification', function () {
    // Given the bell routes
    // When their middleware is inspected
    // Then each has auth:sanctum and active, and none has verified.api
    foreach (notificationRoutes() as $route => $middleware) {
        expect($middleware)->toContain('auth:sanctum', 'active')->not->toContain('verified.api');
    }
});

it('carries the notifications-read limiter on the write route only', function () {
    // Given the bell routes
    // When their named limiters are read
    $limiters = fn (array $middleware) => collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->values()->all();
    $routes = notificationRoutes();

    // Then the write has it and the reads have only the global api bucket
    expect($limiters($routes['POST me/notifications/read']))->toBe(['throttle:notifications-read']);
    expect($limiters($routes['GET me/notifications']))->toBe([])->and($limiters($routes['GET me/notifications/unread-count']))->toBe([]);
});

// ---- Close-out audit: every route this sub-project added, by URI prefix only ----

use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware, for the bell, preference and unsubscribe routes */
function notificationAuditRoutes(): array
{
    $found = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $uri = str_starts_with($route->uri(), 'api/v1/') ? substr($route->uri(), 7) : $route->uri();
        if (! (str_starts_with($uri, 'me/notifications') || str_starts_with($uri, 'me/email-preferences') || str_starts_with($uri, 'email/unsubscribe')) || str_contains($uri, '_probe')) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                $found[$method.' '.$uri] = $route->gatherMiddleware();
            }
        }
    }
    ksort($found);

    return $found;
}

/** The first (per minute) bucket of a named limiter for a member or an address. */
function notificationLimiterMinute(string $name): int
{
    $user = User::factory()->make(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    $request = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']);
    $request->setUserResolver(fn () => $user);
    $limits = RateLimiter::limiter($name)($request);
    $limits = is_array($limits) ? $limits : [$limits];
    $first = $limits[0];
    assert($first instanceof Limit);

    return $first->maxAttempts;
}

it('registers exactly the six routes of the notification system, with the guards the plan names', function () {
    // Given every route under the bell, preference and unsubscribe prefixes
    $routes = notificationAuditRoutes();

    // When they are listed
    // Then there are exactly six
    expect(array_keys($routes))->toBe([
        'GET me/email-preferences', 'GET me/notifications', 'GET me/notifications/unread-count', 'PATCH me/email-preferences', 'POST email/unsubscribe', 'POST me/notifications/read',
    ]);

    // And the five member routes sit behind sign-in and an active account, never behind email verification
    foreach (array_diff_key($routes, ['POST email/unsubscribe' => 1]) as $uri => $middleware) {
        expect($middleware)->toContain('auth:sanctum', 'active')->not->toContain('verified.api');
    }

    // And the public unsubscribe route is signed and throttled before the signature check, with no authentication at all
    $unsubscribe = $routes['POST email/unsubscribe'];
    expect($unsubscribe)->toContain('signed:relative', 'throttle:unsubscribe')->not->toContain('auth:sanctum')->not->toContain('active');
    expect(array_search('throttle:unsubscribe', $unsubscribe, true))->toBeLessThan(array_search('signed:relative', $unsubscribe, true));
});

it('gives every write route a named limiter whose minute bucket is below the group\'s 60', function () {
    // Given the audited routes
    $named = fn (array $middleware) => collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->map(fn (string $m) => substr($m, 9))->values()->all();
    $routes = notificationAuditRoutes();

    // When the write routes' limiters are read
    $writes = ['POST me/notifications/read' => 'notifications-read', 'PATCH me/email-preferences' => 'email-prefs', 'POST email/unsubscribe' => 'unsubscribe'];

    // Then each carries exactly its limiter, and each bucket can bite under the api bucket of 60 (30, 20 and 20)
    foreach ($writes as $route => $limiter) {
        expect($named($routes[$route]))->toBe([$limiter]);
        expect(notificationLimiterMinute($limiter))->toBeLessThan(60);
    }
    expect([notificationLimiterMinute('notifications-read'), notificationLimiterMinute('email-prefs'), notificationLimiterMinute('unsubscribe')])->toBe([30, 20, 20]);

    // And the reads have only the global api bucket
    foreach (['GET me/notifications', 'GET me/notifications/unread-count', 'GET me/email-preferences'] as $read) {
        expect($named($routes[$read]))->toBe([]);
    }
});

it('has no route that lists outbox rows, sends mail or shows another member\'s notifications', function (string $method, string $uri) {
    // Given a signed-in admin and a probe at a URL the design deliberately does not have
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When it is requested
    $status = $this->json($method, $uri)->getStatusCode();

    // Then none exists (404, or 405 where only another verb is routed)
    expect($status)->toBeIn([404, 405]);
})->with([
    ['GET', '/api/v1/email-messages'], ['POST', '/api/v1/email-messages'], ['GET', '/api/v1/notifications'], ['GET', '/api/v1/me/email-messages'],
    ['GET', '/api/v1/staff/email-messages'], ['GET', '/api/v1/admin/email-messages'], ['POST', '/api/v1/email/send'], ['POST', '/api/v1/email-messages/resend'],
    ['GET', '/api/v1/users/someone/notifications'], ['GET', '/api/v1/admin/notifications'], ['DELETE', '/api/v1/me/notifications'],
]);

it('answers every route with no-store, private for success, 401, 403, 422 and 429', function () {
    // Given a member, a suspended member, and signed unsubscribe links
    $member = User::factory()->create();
    $suspended = User::factory()->suspended()->create();
    $signed = fn (string $type) => URL::signedRoute('email.unsubscribe', ['user' => $member->id, 'type' => $type], absolute: false);
    $seen = [];
    $check = function (string $label, TestResponse $response, int $status) use (&$seen) {
        $response->assertStatus($status);
        $seen[$label] = (string) $response->headers->get('Cache-Control');
    };

    // When each route is requested as a guest (401), as a suspended member (403), with a bad payload (422) and normally (200)
    $check('GET me/notifications 401', $this->getJson('/api/v1/me/notifications'), 401);
    $check('GET me/notifications/unread-count 401', $this->getJson('/api/v1/me/notifications/unread-count'), 401);
    $check('POST me/notifications/read 401', $this->postJson('/api/v1/me/notifications/read', ['all' => true]), 401);
    $check('GET me/email-preferences 401', $this->getJson('/api/v1/me/email-preferences'), 401);
    $check('PATCH me/email-preferences 401', $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['daily_top5' => false]]), 401);
    $check('POST email/unsubscribe 403 (unsigned)', $this->postJson('/api/v1/email/unsubscribe?user='.$member->id.'&type=daily_top5'), 403);

    Sanctum::actingAs($suspended);
    $check('GET me/notifications 403', $this->getJson('/api/v1/me/notifications'), 403);
    $check('GET me/notifications/unread-count 403', $this->getJson('/api/v1/me/notifications/unread-count'), 403);
    $check('POST me/notifications/read 403', $this->postJson('/api/v1/me/notifications/read', ['all' => true]), 403);
    $check('GET me/email-preferences 403', $this->getJson('/api/v1/me/email-preferences'), 403);
    $check('PATCH me/email-preferences 403', $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['daily_top5' => false]]), 403);

    Sanctum::actingAs($member);
    $check('GET me/notifications 422', $this->getJson('/api/v1/me/notifications?per_page=999'), 422);
    $check('GET me/notifications 200', $this->getJson('/api/v1/me/notifications'), 200);
    $check('GET me/notifications/unread-count 200', $this->getJson('/api/v1/me/notifications/unread-count'), 200);
    $check('POST me/notifications/read 422', $this->postJson('/api/v1/me/notifications/read', ['ids' => 'x']), 422);
    $check('POST me/notifications/read 200', $this->postJson('/api/v1/me/notifications/read', ['all' => true]), 200);
    $check('GET me/email-preferences 200', $this->getJson('/api/v1/me/email-preferences'), 200);
    $check('PATCH me/email-preferences 422', $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['verify_email' => false]]), 422);
    $check('PATCH me/email-preferences 200', $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['daily_top5' => false]]), 200);
    $check('POST email/unsubscribe 422', $this->postJson($signed('verify_email')), 422);
    $check('POST email/unsubscribe 200', $this->postJson($signed('weekly_best')), 200);

    // And 429 from each throttle: the write limiters (30, 20 and 20 a minute) and the global bucket for a read (60)
    foreach (range(1, 31) as $_) {
        $last = $this->postJson('/api/v1/me/notifications/read', ['all' => true]);
    }
    $check('POST me/notifications/read 429', $last, 429);
    foreach (range(1, 21) as $_) {
        $last = $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['daily_top5' => false]]);
    }
    $check('PATCH me/email-preferences 429', $last, 429);
    foreach (range(1, 21) as $_) {
        $last = $this->postJson($signed('weekly_best'));
    }
    $check('POST email/unsubscribe 429', $last, 429);
    foreach (range(1, 61) as $_) {
        $last = $this->getJson('/api/v1/me/notifications/unread-count');
    }
    $check('GET me/notifications/unread-count 429', $last, 429);

    // Then every response, whatever its status, was marked uncacheable
    expect($seen)->not->toBeEmpty();
    foreach ($seen as $label => $header) {
        expect($header)->toContain('no-store')->toContain('private');
    }
});
