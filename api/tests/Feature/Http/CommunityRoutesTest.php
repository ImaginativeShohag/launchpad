<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware, for routes whose path matches the pattern */
function communityRoutes(string $pattern): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        if (! str_starts_with($route->uri(), 'api/v1/') || str_contains($route->uri(), '_')) {
            continue;
        }

        $path = substr($route->uri(), strlen('api/v1/'));

        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true) && preg_match($pattern, $path) === 1) {
                $found[$method.' '.$path] = $route->gatherMiddleware();
            }
        }
    }

    ksort($found);

    return $found;
}

/** @return list<string> the named limiters other than the global api bucket */
function communityLimiters(array $middleware): array
{
    return collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')
        ->map(fn (string $m) => substr($m, strlen('throttle:')))->values()->all();
}

/** The routes this sub-project added (and the one older route whose name matches the pattern), with the limiter each write carries. */
const COMMUNITY_ROUTE_LIMITERS = [
    'GET products/{slug}/comments' => null,
    'GET comments/{comment}/replies' => null,
    'POST products/{product}/comments' => 'comment-create',
    'PATCH comments/{comment}' => 'comment-modify',
    'DELETE comments/{comment}' => 'comment-modify',
    'PUT users/{user}/follow' => 'follow',
    'DELETE users/{user}/follow' => 'follow',
    'PUT topics/{topic}/follow' => 'follow',
    'DELETE topics/{topic}/follow' => 'follow',
    'GET me/follows/users' => null,
    'GET me/follows/topics' => null,
    'GET me/feed' => null,
    'POST comments/{comment}/report' => 'report',
    'POST products/{product}/report' => 'report',
    'GET staff/reports' => null,
    'GET staff/reports/history' => null,
    'GET staff/reports/{type}/{id}' => null,
    'POST staff/reports/{type}/{id}/resolve' => 'admin-write',
    'POST admin/users/{user}/suspend' => 'admin-write',
    'POST admin/users/{user}/restore' => 'admin-write',
];

it('has exactly the community routes and nothing else that looks like one', function () {
    // Given every registered route whose path mentions a comment, follow, report, feed, suspend or restore
    // When they are listed
    $found = array_keys(communityRoutes('~comment|follow|report|feed|suspend|restore~i'));

    // Then they are the documented ones (plus the older product restore and the anti-cheat risk panel's suspend, whose own audit is AntiCheatRoutesTest), so no follower list, comment-like or other extra exists
    $expected = [...array_keys(COMMUNITY_ROUTE_LIMITERS), 'POST staff/products/{product}/restore', 'POST staff/risk/members/{member}/suspend'];
    sort($expected);
    expect($found)->toBe($expected);
    expect(communityRoutes('~follower|likes?$|upvote|react~i'))->toBe([]);
});

it('puts exactly one named limiter, from the spec, on every community write route, and none beyond the api bucket on reads', function () {
    // Given the community routes
    $routes = communityRoutes('~comment|follow|report|feed|suspend|restore~i');

    // When each route's limiters are read
    // Then writes carry exactly the documented one, reads none beyond the global api limiter
    foreach (COMMUNITY_ROUTE_LIMITERS as $route => $limiter) {
        expect(communityLimiters($routes[$route]))->toBe($limiter === null ? [] : [$limiter], $route);
    }
});

it('keeps every community limiter below the 60 per minute api bucket so it can bite', function () {
    // Given a signed-in request
    $user = User::factory()->create();
    $request = tap(Request::create('/x', 'POST'), fn (Request $r) => $r->setUserResolver(fn () => $user));
    $minute = fn (string $name) => collect(Arr::wrap(RateLimiter::limiter($name)($request)))->first(fn ($limit) => $limit->decaySeconds === 60);

    // When the minute buckets of the named limiters are read
    // Then each is lower than the api group's 60
    expect($minute('api')->maxAttempts)->toBe(60);
    foreach (array_unique(array_filter(COMMUNITY_ROUTE_LIMITERS)) as $name) {
        expect($minute($name)->maxAttempts)->toBeLessThan(60, $name);
    }
});

it('puts sign-in, an active account and a confirmed email on every member, staff and admin community route, and none on the public reads', function () {
    // Given the community routes
    $routes = communityRoutes('~comment|follow|report|feed|suspend|restore~i');
    $public = ['GET products/{slug}/comments', 'GET comments/{comment}/replies'];

    // When their middleware is inspected
    // Then all but the two public reads carry the three guards, and the two reads none
    foreach (array_keys(COMMUNITY_ROUTE_LIMITERS) as $route) {
        if (in_array($route, $public, true)) {
            expect($routes[$route])->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api');
        } else {
            expect($routes[$route])->toContain('auth:sanctum', 'active', 'verified.api');
        }
    }
});

it('marks every authenticated community prefix no-store for writes and leaves the public reads cacheable', function () {
    // Given a guest (the headers are set by a global middleware, so even the 401s carry them)
    $writes = [
        ['post', '/api/v1/products/x/comments'], ['patch', '/api/v1/comments/x'], ['delete', '/api/v1/comments/x'],
        ['put', '/api/v1/users/x/follow'], ['delete', '/api/v1/users/x/follow'], ['put', '/api/v1/topics/x/follow'], ['delete', '/api/v1/topics/x/follow'],
        ['post', '/api/v1/comments/x/report'], ['post', '/api/v1/products/x/report'],
        ['get', '/api/v1/me/feed'], ['get', '/api/v1/me/follows/users'], ['get', '/api/v1/me/follows/topics'],
        ['get', '/api/v1/staff/reports'], ['get', '/api/v1/staff/reports/history'], ['get', '/api/v1/staff/reports/comment/x'], ['post', '/api/v1/staff/reports/comment/x/resolve'],
        ['post', '/api/v1/admin/users/x/suspend'], ['post', '/api/v1/admin/users/x/restore'],
    ];

    // When each is called
    // Then it is no-store, private (any method under me, staff and admin; the non-cacheable ones elsewhere)
    foreach ($writes as [$method, $uri]) {
        expect($this->{$method.'Json'}($uri)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }

    // And the public reads are not
    foreach (['/api/v1/products/x/comments', '/api/v1/comments/x/replies', '/api/v1/users/x', '/api/v1/topics'] as $uri) {
        expect($this->getJson($uri)->headers->get('Cache-Control'))->not->toContain('no-store');
    }
});

it('answers a suspended or unverified member the same guard 403 on every community id route, existing id or not', function (string $state, string $code) {
    // Given a member who may not act, and real targets
    $member = signedInMember();
    $state === 'suspended' ? $member->forceFill(['suspended_at' => now()])->save() : $member->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create();
    $topic = Topic::factory()->create(['slug' => 'real-topic']);
    $other = User::factory()->create(['handle' => 'real-member']);
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When every id-taking community route is called with a real id and with a missing one
    $calls = fn (string $id, string $handle, string $slug) => [
        ['post', "/api/v1/products/{$id}/comments"], ['patch', "/api/v1/comments/{$id}"], ['delete', "/api/v1/comments/{$id}"],
        ['put', "/api/v1/users/{$handle}/follow"], ['delete', "/api/v1/users/{$handle}/follow"], ['put', "/api/v1/topics/{$slug}/follow"], ['delete', "/api/v1/topics/{$slug}/follow"],
        ['post', "/api/v1/comments/{$id}/report"], ['post', "/api/v1/products/{$id}/report"],
        ['get', "/api/v1/staff/reports/comment/{$id}"], ['get', "/api/v1/staff/reports/product/{$id}"], ['post', "/api/v1/staff/reports/comment/{$id}/resolve"], ['post', "/api/v1/staff/reports/product/{$id}/resolve"],
        ['post', "/api/v1/admin/users/{$handle}/suspend"], ['post', "/api/v1/admin/users/{$handle}/restore"],
    ];

    // Then every one is the guard's 403, identical
    foreach ([...$calls($product->id, $other->handle, $topic->slug), ...$calls($missing, 'no-such-member', 'no-such-topic')] as [$method, $uri]) {
        $this->{$method.'Json'}($uri, ['reason' => 'x', 'action' => 'dismiss', 'body' => 'x'])->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a plain member 403 on every staff and admin community route, for real and unknown ids alike, and a guest 401', function () {
    // Given a plain member and some targets
    Sanctum::actingAs(User::factory()->create());
    $real = Product::factory()->live()->create()->id;
    $other = User::factory()->create(['handle' => 'real-member']);
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $calls = fn (string $id, string $handle) => [
        ['get', '/api/v1/staff/reports'], ['get', '/api/v1/staff/reports/history'], ['get', "/api/v1/staff/reports/comment/{$id}"], ['get', "/api/v1/staff/reports/product/{$id}"],
        ['post', "/api/v1/staff/reports/comment/{$id}/resolve"], ['post', "/api/v1/staff/reports/product/{$id}/resolve"],
        ['post', "/api/v1/admin/users/{$handle}/suspend"], ['post', "/api/v1/admin/users/{$handle}/restore"],
    ];

    // When each is called with an existing and with a missing id (and a body that would fail validation)
    // Then a member gets 403 everywhere, with the same message
    $messages = [];
    foreach ([...$calls($real, $other->handle), ...$calls($missing, 'no-such-member')] as [$method, $uri]) {
        $messages[] = $this->{$method.'Json'}($uri, ['action' => ['x'], 'reason' => ['x']])->assertForbidden()->json('message');
    }
    expect(array_unique($messages))->toHaveCount(1);

    // And a guest gets 401 on all of them
    $this->app['auth']->forgetGuards();
    foreach ([...$calls($real, $other->handle), ...$calls($missing, 'no-such-member')] as [$method, $uri]) {
        $this->{$method.'Json'}($uri)->assertUnauthorized();
    }
});

it('lets a reviewer reach the report routes but not the suspension routes', function () {
    // Given a reviewer
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When they open the queue and try to suspend someone
    // Then the queue answers and suspension is admin only
    $this->getJson('/api/v1/staff/reports')->assertOk();
    $this->postJson('/api/v1/admin/users/anyone/suspend', ['reason' => 'x'])->assertForbidden();
    $this->postJson('/api/v1/admin/users/anyone/restore')->assertForbidden();
});

it('schedules the deleted-member comment sweep once, at minute 30, behind an overlap lock on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:sweep-deleted-member-comments'));

    // When the entry is inspected
    // Then it exists once with the documented expression and protection (the exact total of events is pinned in VotingRoutesTest)
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('30 * * * *')->and($event->expiresAt)->toBe(10)->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
