<?php

use App\Actions\Export\RequestExport;
use App\Actions\Export\SweepExports;
use App\Actions\Retention\PurgeExports;
use App\Actions\Views\FlushViews;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware, for every route of this sub-project */
function dashRoutesAll(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || str_contains($uri, '_') || preg_match('~dashboard|exports|/view|referrers~', $uri) !== 1) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                $routes[$method.' '.substr($uri, 7)] = $route->gatherMiddleware();
            }
        }
    }
    ksort($routes);

    return $routes;
}

/** The throttle names (other than the api bucket) of a middleware list. */
function dashRoutesThrottles(array $middleware): array
{
    return array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api'));
}

it('has exactly the routes of the sub-project and nothing else matching dashboard, exports, view or referrers', function () {
    // Given the registered routes
    // When every one that matches the sub-project's words is listed
    $keys = array_keys(dashRoutesAll());

    // Then they are exactly the documented set: no staff export, no cross-product export list, no file path route
    $expected = [
        'POST products/{product}/view',
        'GET me/products/{product}/dashboard', 'GET me/products/{product}/dashboard/series', 'GET me/products/{product}/dashboard/rank', 'GET me/products/{product}/dashboard/referrers',
        'GET staff/products/{product}/dashboard', 'GET staff/products/{product}/dashboard/series', 'GET staff/products/{product}/dashboard/rank', 'GET staff/products/{product}/dashboard/referrers',
        'POST me/products/{product}/exports', 'GET me/products/{product}/exports', 'GET me/products/{product}/exports/{export}', 'GET me/products/{product}/exports/{export}/download',
    ];
    sort($expected);
    expect($keys)->toBe($expected);
    foreach ($keys as $key) {
        expect($key)->not->toContain('file_path')->not->toContain('staff/products/{product}/exports');
    }
});

it('puts the beacon outside the auth guards and every other route inside all three', function () {
    // Given the routes
    foreach (dashRoutesAll() as $key => $middleware) {
        if ($key === 'POST products/{product}/view') {
            // Then the beacon is public with exactly its own limiter
            expect($middleware)->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api');
            expect(dashRoutesThrottles($middleware))->toBe(['throttle:view']);

            continue;
        }

        // And every other route carries all three guards
        expect($middleware)->toContain('auth:sanctum')->toContain('active')->toContain('verified.api');
    }
});

it('gives every write route one named non-api throttle and every named limiter a minute bucket below 60 or none', function () {
    // Given the routes
    $writes = array_filter(dashRoutesAll(), fn ($middleware, $key) => str_starts_with($key, 'POST '), ARRAY_FILTER_USE_BOTH);

    // When their throttles are read
    // Then each write route has exactly one, and it is view or export-start
    expect(array_map(fn ($m) => dashRoutesThrottles($m), $writes))->toBe(['POST me/products/{product}/exports' => ['throttle:export-start'], 'POST products/{product}/view' => ['throttle:view']]);

    // And every limiter of the sub-project has no minute bucket at or above the api bucket's 60
    $request = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']);
    $request->setUserResolver(fn () => User::factory()->make(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']));
    foreach (['view', 'dashboard', 'export-start', 'export-poll', 'export-download'] as $name) {
        $limits = Arr::wrap(RateLimiter::limiter($name)($request));
        expect($limits)->not->toBeEmpty();
        foreach ($limits as $limit) {
            /** @var Limit $limit */
            // (the beacon's per-IP backstop is deliberately above the api bucket: it only caps a whole address, the per-device bucket is the one that bites)
            if ($limit->decaySeconds <= 60 && ! str_starts_with($limit->key, 'view-ip:')) {
                expect($limit->maxAttempts)->toBeLessThan(60, $name);
            }
        }
    }
});

it('marks every me and staff route of the sub-project, the download included, no-store and private', function () {
    // Given a guest (401) calling every route with a well-formed id
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    foreach (array_keys(dashRoutesAll()) as $key) {
        [$method, $uri] = explode(' ', $key);
        $response = $this->json($method, '/api/v1/'.str_replace(['{product}', '{export}'], $id, $uri));

        // Then every response is no-store and private (the beacon included, resolution 6)
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('answers the beacon with no-store, private for a counted view too', function () {
    // Given a visible product
    viewRedisReset();
    $product = viewVisibleProduct();

    // When a beacon is counted
    $response = viewBeacon($product->id, [], viewDevice('lock-recap'));

    // Then it is 204 and still no-store, private
    $response->assertNoContent();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('answers a suspended and an unverified member one 403 for an existing and a missing id on every id route, a guest 401', function (string $state, string $code) {
    // Given a member who may not use the routes and a product of theirs
    $member = signedInMember();
    $state === 'suspended' ? $member->forceFill(['suspended_at' => now()])->save() : $member->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create(['maker_id' => $member->id]);
    $routes = array_filter(array_keys(dashRoutesAll()), fn ($key) => $key !== 'POST products/{product}/view');

    foreach ($routes as $key) {
        [$method, $uri] = explode(' ', $key);
        foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            // When the route is called with an existing and a missing id
            $path = '/api/v1/'.str_replace(['{product}', '{export}'], [$id, '01ARZ3NDEKTSV4RRFFQ69G5FAW'], $uri);

            // Then every response is the guard's 403
            $this->json($method, $path)->assertForbidden()->assertJsonPath('code', $code);
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a guest 401 and a non-staff member 403 on the staff routes for existing and unknown ids alike', function () {
    // Given a product and an unknown id
    $product = Product::factory()->live()->create();
    $ids = [$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
    $staff = array_filter(array_keys(dashRoutesAll()), fn ($key) => str_starts_with($key, 'GET staff/'));

    // When a guest calls them, then an ordinary member
    foreach ($staff as $key) {
        foreach ($ids as $id) {
            $this->getJson('/api/v1/'.str_replace('{product}', $id, substr($key, 4)))->assertUnauthorized();
        }
    }
    Sanctum::actingAs(User::factory()->create());
    $bodies = [];
    foreach ($staff as $key) {
        foreach ($ids as $id) {
            $bodies[] = $this->getJson('/api/v1/'.str_replace('{product}', $id, substr($key, 4)))->assertForbidden()->json();
        }
    }

    // Then the member's refusal is one and the same answer
    expect(collect($bodies)->unique()->count())->toBe(1);
});

it('schedules fourteen commands, flush-views and sweep-exports once each with their locks, and no extra event', function () {
    // Given the schedule
    $events = collect(app(Schedule::class)->events());
    $entry = fn (string $command) => $events->filter(fn ($e) => str_contains((string) $e->command, $command));

    // When the two commands of this sub-project are read
    // Then the total is 14 (the abuse scan is the fourteenth) and each of the two is registered once with the documented frequency and protection
    expect($events)->toHaveCount(14);
    foreach (['launchpad:flush-views' => ['* * * * *', 5], 'launchpad:sweep-exports' => ['*/5 * * * *', 10]] as $command => [$expression, $lock]) {
        expect($entry($command))->toHaveCount(1);
        $event = $entry($command)->first();
        expect([$event->expression, $event->expiresAt, $event->withoutOverlapping, $event->onOneServer])->toBe([$expression, $lock, true, true], $command);
    }
});

it('locks only what the lock order allows: flush product key shares; request users share then exports; the rest no product or user lock', function () {
    // Given a live product with a pending view and a ready export, a queued export of another product, and a failed old one
    viewRedisReset();
    Storage::fake('exports');
    Queue::fake();
    $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC'));
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 1, "r|{$product->id}|2026-10-21|google" => 1]);
    $mine = Product::factory()->live()->create();
    exportFor($mine, 'ready', ['expires_at' => now()->subMinute()]);
    $stuck = exportFor(Product::factory()->live()->create(), 'processing', ['updated_at' => now()->subMinutes(30), 'created_at' => now()->subMinutes(30)]);
    $queued = exportFor(Product::factory()->live()->create(), 'queued');

    // When each piece runs
    $flush = exportLockingStatements(fn () => app(FlushViews::class)->handle());
    $request = exportLockingStatements(fn () => app(RequestExport::class)->handle($mine->maker, $mine->id));
    $generate = exportLockingStatements(fn () => exportGenerate($queued));
    $purge = exportLockingStatements(fn () => retentionPurger(PurgeExports::class, 10, 2)->purge(CarbonImmutable::now('UTC')));
    $sweep = exportLockingStatements(fn () => app(SweepExports::class)->handle());

    // Then the flush takes product key shares and nothing else
    expect($flush)->not->toBeEmpty();
    foreach ($flush as $sql) {
        expect($sql)->toContain('from "products"')->toContain('for key share');
    }

    // And the request takes the user row for share first, then export rows for update, and no product row
    expect($request[0])->toContain('from "users"')->toContain('for share');
    foreach (array_slice($request, 1) as $sql) {
        expect($sql)->toContain('from "exports"')->toContain('for update');
    }

    // And the job, the purge and the sweeper lock export rows only
    foreach ([$generate, $purge, $sweep] as $statements) {
        expect($statements)->not->toBeEmpty();
        foreach ($statements as $sql) {
            expect($sql)->toContain('exports')->not->toContain('from "products"')->not->toContain('from "users"');
        }
    }
    expect($stuck->refresh()->error)->toBe('stalled');
});
