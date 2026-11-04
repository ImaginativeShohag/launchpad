<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

const VOTING_API_PREFIX = 'api/v1/';

/** @return list<LaravelRoute> */
function votingApiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), VOTING_API_PREFIX))
        ->values()->all();
}

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware, for routes whose uri matches the pattern */
function votingRoutesMatching(string $pattern): array
{
    $found = [];

    foreach (votingApiRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true) || preg_match($pattern, substr($route->uri(), strlen(VOTING_API_PREFIX))) !== 1) {
                continue;
            }
            $found[$method.' '.substr($route->uri(), strlen(VOTING_API_PREFIX))] = $route->gatherMiddleware();
        }
    }

    return $found;
}

it('guards both vote writes with sign-in, an active account and a confirmed email, and keeps the vote limiter below the api bucket', function () {
    // Given the registered vote routes
    $routes = votingRoutesMatching('~^products/\{[^}]+\}/vote$~');

    // When their middleware is inspected
    // Then exactly the cast and the withdraw exist, each with the three guards (the limiter name is pinned in ProductRoutesTest)
    expect(array_keys($routes))->toBe(['PUT products/{product}/vote', 'DELETE products/{product}/vote']);
    foreach ($routes as $middleware) {
        expect($middleware)->toContain('auth:sanctum', 'active', 'verified.api');
    }

    // And the vote limiter's minute bucket is lower than the group's 60/min bucket (a named limiter at or above it can never bite)
    $user = User::factory()->create();
    $request = tap(Request::create('/x', 'PUT'), fn (Request $r) => $r->setUserResolver(fn () => $user));
    $minute = collect(Arr::wrap(RateLimiter::limiter('vote')($request)))->first(fn ($limit) => $limit->decaySeconds === 60);
    $group = collect(Arr::wrap(RateLimiter::limiter('api')($request)))->first(fn ($limit) => $limit->decaySeconds === 60);
    expect($minute->maxAttempts)->toBeLessThan($group->maxAttempts)->and($group->maxAttempts)->toBe(60);
});

it('answers the public reads to a guest without any sign-in requirement', function () {
    // Given a product launching today and no signed-in user
    $this->travelTo(new DateTimeImmutable('2026-10-20 12:00:00 UTC'));
    Product::factory()->live(now('UTC')->toDateString())->create();

    // When a guest reads today, a day and a leaderboard
    $today = $this->getJson('/api/v1/today');
    $day = $this->getJson('/api/v1/days/'.now('UTC')->toDateString());
    $board = $this->getJson('/api/v1/leaderboards?period=all');

    // Then all three answer 200, and none carries a named limiter beyond the group's (the global api limiter covers them)
    foreach ([$today, $day, $board] as $response) {
        $response->assertOk();
    }
    foreach (votingRoutesMatching('~^(today|days/\{date\}|leaderboards)$~') as $route => $middleware) {
        expect($middleware)->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api')
            ->and(collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->all())->toBe([], $route);
    }
});

it('exposes no way to list who voted: no read route under a vote path, and probes find nothing', function () {
    // Given the registered routes and a product launching today
    $this->travelTo(new DateTimeImmutable('2026-10-20 12:00:00 UTC'));
    // When every route whose path mentions a vote is collected
    $voteRoutes = votingRoutesMatching('~vote~i');

    // Then only the two member writes and the staff-only anti-cheat routes exist (the staff prefix is role-gated; the two voids are admin-only), no public read returns voters
    expect(array_keys($voteRoutes))->toBe(['PUT products/{product}/vote', 'DELETE products/{product}/vote', 'POST staff/risk/members/{member}/void-votes', 'POST staff/votes/{vote}/void', 'GET staff/products/{product}/vote-integrity', 'GET staff/products/{product}/votes']);
    expect(votingRoutesMatching('~voter|likes|upvote~i'))->toBe([]);

    // And probing the obvious voter-list paths answers 404 or 405, for a guest and for a member, never data
    $product = Product::factory()->live(now('UTC')->toDateString())->create();
    $member = User::factory()->create();
    foreach ([null, $member] as $viewer) {
        $viewer === null ? $this->app['auth']->forgetGuards() : $this->actingAs($viewer, 'sanctum');
        foreach (["/api/v1/products/{$product->id}/votes", "/api/v1/products/{$product->slug}/votes", "/api/v1/products/{$product->id}/voters", "/api/v1/products/{$product->id}/vote"] as $path) {
            expect($this->getJson($path)->status())->toBeIn([401, 404, 405], $path);
        }
    }
});

it('registers each scheduled launchpad command exactly once with its frequency, overlap lock and one-server flag', function () {
    // Given the application's schedule
    $entries = collect(app(Schedule::class)->events())
        ->map(fn ($event) => [preg_match('/launchpad:[a-z-]+/', (string) $event->command, $m) === 1 ? $m[0] : null, $event])
        ->filter(fn (array $pair) => $pair[0] !== null);

    // When the entries are grouped by command
    $byCommand = $entries->groupBy(fn (array $pair) => $pair[0])->map(fn ($group) => $group->map(fn (array $pair) => [
        'expression' => $pair[1]->expression, 'lock_minutes' => $pair[1]->expiresAt,
        'without_overlapping' => $pair[1]->withoutOverlapping, 'one_server' => $pair[1]->onOneServer,
    ])->all());

    // Then the fourteen jobs are each registered once, every one protected the same way, and no other event is scheduled at all
    expect(app(Schedule::class)->events())->toHaveCount(14);
    expect($byCommand->keys()->sort()->values()->all())->toBe([
        'launchpad:detect-abuse', 'launchpad:flush-views', 'launchpad:freeze-day', 'launchpad:publish-launches', 'launchpad:purge-retention', 'launchpad:queue-digests', 'launchpad:queue-launch-reminders', 'launchpad:recompute-hot-scores', 'launchpad:reconcile-notifications', 'launchpad:send-emails', 'launchpad:snapshot-ranks', 'launchpad:sweep-deleted-member-comments', 'launchpad:sweep-deleted-member-votes', 'launchpad:sweep-exports',
    ]);
    expect($byCommand->map(fn ($group) => count($group))->all())->each->toBe(1);
    $expected = [
        'launchpad:publish-launches' => ['* * * * *', 5],
        'launchpad:recompute-hot-scores' => ['* * * * *', 5],
        'launchpad:freeze-day' => ['* * * * *', 5],
        'launchpad:snapshot-ranks' => ['*/10 * * * *', 15],
        'launchpad:sweep-deleted-member-votes' => ['0 * * * *', 10],
        'launchpad:sweep-deleted-member-comments' => ['30 * * * *', 10],
        'launchpad:purge-retention' => ['15 3 * * *', 60],
        'launchpad:send-emails' => ['* * * * *', 5],
        'launchpad:reconcile-notifications' => ['45 * * * *', 30],
        'launchpad:queue-launch-reminders' => ['*/10 * * * *', 15],
        'launchpad:queue-digests' => ['*/10 * * * *', 60],
        'launchpad:flush-views' => ['* * * * *', 5],
        'launchpad:sweep-exports' => ['*/5 * * * *', 10],
        'launchpad:detect-abuse' => ['*/5 * * * *', 10],
    ];
    // The long-running commands (the two queue commands and the abuse scan) also run in the background, so a slow digest never delays the other entries
    $scheduled = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event) => [(preg_match('/launchpad:[a-z-]+/', (string) $event->command, $m) === 1 ? $m[0] : '') => $event->runInBackground]);
    expect($scheduled['launchpad:queue-digests'])->toBeTrue()->and($scheduled['launchpad:queue-launch-reminders'])->toBeTrue()->and($scheduled['launchpad:detect-abuse'])->toBeTrue();
    foreach ($expected as $command => [$expression, $lock]) {
        expect($byCommand[$command][0])->toBe(['expression' => $expression, 'lock_minutes' => $lock, 'without_overlapping' => true, 'one_server' => true], $command);
    }
});
