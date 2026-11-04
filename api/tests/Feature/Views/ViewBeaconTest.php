<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use App\Support\ViewCounters;
use Carbon\CarbonImmutable;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable(VIEW_NOON.' UTC'));
});

it('counts a first view: 204, an empty body, no-store, and the hour and referrer fields at one', function () {
    // Given a product launching today
    $product = viewVisibleProduct();

    // When a device sends the beacon
    $response = viewBeacon($product->id, [], viewDevice('device-a'));

    // Then it is an empty 204 with the non-cacheable header and one view is counted under direct
    $response->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->toBe('');
    $hour = intdiv(now('UTC')->getTimestamp(), 3600) * 3600;
    expect(viewPendingHash())->toBe(["p|{$product->id}|{$hour}" => '1', "r|{$product->id}|2026-10-21|direct" => '1']);
});

it('does not count the same device twice in a day, counts another device, and counts again the next UTC day', function () {
    // Given a counted view
    $product = viewVisibleProduct();
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();

    // When the same device repeats, another arrives, and the next day begins
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();
    expect(array_sum(viewRefFields()))->toBe(1);
    viewBeacon($product->id, [], viewDevice('device-b'))->assertNoContent();
    expect(array_sum(viewRefFields()))->toBe(2);
    $this->travelTo(new DateTimeImmutable('2026-10-22 00:00:10 UTC'));
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();

    // Then each was counted once per device per day
    expect(viewRefFields())->toBe(["r|{$product->id}|2026-10-21|direct" => 2, "r|{$product->id}|2026-10-22|direct" => 1]);
});

it('counts from the first second of the launch day', function () {
    // Given a product and the clock 30 seconds into its launch day
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:30 UTC'));
    $product = viewVisibleProduct();

    // When the beacon arrives
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();

    // Then it counts in the midnight hour
    $hour = intdiv(now('UTC')->getTimestamp(), 3600) * 3600;
    expect(viewHourFields())->toBe(["p|{$product->id}|{$hour}" => 1]);
});

it('does not count the maker, a reviewer or an admin, but answers 204', function (string $who) {
    // Given a product and a signed-in caller who is its maker, a reviewer or an admin
    $product = viewVisibleProduct();
    match ($who) {
        'maker' => Sanctum::actingAs($product->maker),
        'reviewer' => signedInStaff(Role::Reviewer),
        'admin' => signedInStaff(Role::Admin),
    };

    // When the beacon arrives
    $response = viewBeacon($product->id, [], viewDevice('device-a'));

    // Then it is 204 and nothing is counted
    $response->assertNoContent();
    expect(viewPendingHash())->toBe([]);
})->with(['maker', 'reviewer', 'admin']);

it('counts a signed-in member who is not the maker', function () {
    // Given a member and someone else's product
    $product = viewVisibleProduct();
    signedInMember();

    // When the beacon arrives
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();

    // Then it counts
    expect(array_sum(viewRefFields()))->toBe(1);
});

it('treats a suspended member and an invalid bearer token as a guest', function () {
    // Given a product, a suspended member and a junk token
    $product = viewVisibleProduct();
    Sanctum::actingAs(User::factory()->suspended()->create());

    // When each sends the beacon from a different device
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken('junk-token')->postJson("/api/v1/products/{$product->id}/view", [], viewDevice('device-b') + ['User-Agent' => 'Mozilla/5.0 Chrome/120'])->assertNoContent();

    // Then both count as guests
    expect(array_sum(viewRefFields()))->toBe(2);
});

it('does not count a suspended maker\'s own view but counts their live product for others', function () {
    // Given a live product whose maker is suspended
    $maker = User::factory()->suspended()->create();
    $product = viewVisibleProduct(['maker_id' => $maker->id]);

    // When a visitor sends the beacon
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();

    // Then it counts
    expect(array_sum(viewRefFields()))->toBe(1);
});

it('does not count bots or an empty user agent, but answers 204', function (string $agent) {
    // Given a product
    $product = viewVisibleProduct();

    // When a bot or an agentless client sends the beacon
    $response = viewBeacon($product->id, [], viewDevice('device-a') + ['User-Agent' => $agent]);

    // Then it is 204 and nothing is counted
    $response->assertNoContent();
    expect(viewPendingHash())->toBe([]);
})->with(['Googlebot/2.1', 'HeadlessChrome/120', 'curl/8.0', '', '   ']);

it('does not count the 26th distinct device of one IP', function () {
    // Given 25 distinct devices from one address
    $product = viewVisibleProduct();
    foreach (range(1, 25) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
        viewBeacon($product->id, [], viewDevice("device-{$i}"))->assertNoContent();
    }
    expect(array_sum(viewRefFields()))->toBe(25);

    // When a 26th distinct device arrives from the same address
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    $response = viewBeacon($product->id, [], viewDevice('device-26'));

    // Then it is still 204 and the count stays 25
    $response->assertNoContent();
    expect(array_sum(viewRefFields()))->toBe(25);
});

it('sorts the referrer into the right source field', function (string $referrer, string $source) {
    // Given a product
    $product = viewVisibleProduct();

    // When the beacon carries a referrer
    viewBeacon($product->id, ['referrer' => $referrer], viewDevice('device-a'))->assertNoContent();

    // Then the source field is the reduced key or host
    expect(viewRefFields())->toBe(["r|{$product->id}|2026-10-21|{$source}" => 1]);
})->with([
    ['https://www.google.com/x', 'google'],
    ['https://t.co/a', 'x'],
    ['http://localhost:5173/today', 'launchpad'],
    ['javascript:alert(1)', 'direct'],
    ['https://blog.example/p?q=1#f', 'blog.example'],
]);

it('answers the identical 404 for every hidden product and counts nothing', function (string $case) {
    // Given a product in some hidden state
    $deletedMaker = User::factory()->create(['deleted_at' => now()]);
    $today = now('UTC')->toDateString();
    $id = match ($case) {
        'draft' => Product::factory()->complete()->create()->id,
        'pending' => Product::factory()->pending()->create()->id,
        'changes requested' => Product::factory()->changesRequested()->create()->id,
        'approved' => Product::factory()->approved()->create()->id,
        'scheduled' => Product::factory()->scheduled($today)->create()->id,
        'scheduled future' => Product::factory()->scheduled()->create()->id,
        'rejected' => Product::factory()->rejected()->create()->id,
        'taken down' => Product::factory()->takenDown()->create(['launch_date' => $today])->id,
        'launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create()->id,
        'soft deleted' => viewVisibleProduct(['deleted_at' => now()])->id,
        'deleted maker' => viewVisibleProduct(['maker_id' => $deletedMaker->id])->id,
        'unknown ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'not a ulid' => 'not-an-id',
        'long' => str_repeat('a', 200),
        'nul byte' => 'x%00y',
    };

    // When the beacon arrives
    $response = viewBeacon($id, [], viewDevice('device-a'));

    // Then it is the plain 404 and nothing is counted
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(viewPendingHash())->toBe([]);
})->with(['draft', 'pending', 'changes requested', 'approved', 'scheduled', 'scheduled future', 'rejected', 'taken down', 'launching tomorrow', 'soft deleted', 'deleted maker', 'unknown ulid', 'not a ulid', 'long', 'nul byte']);

it('still hands a cookie-less visitor an lp_did cookie and counts the visit once', function () {
    // Given a product and a request without any device id
    $product = viewVisibleProduct();

    // When it sends the beacon
    $response = viewBeacon($product->id);

    // Then the device cookie is issued and one view is counted
    $response->assertNoContent()->assertCookie('lp_did', null, false);
    expect(array_sum(viewRefFields()))->toBe(1);
});

it('keeps the dedupe when the same cookie comes back', function () {
    // Given a visitor who got a cookie from their first beacon
    $product = viewVisibleProduct();
    $first = viewBeacon($product->id);
    $cookie = $first->getCookie('lp_did', false)->getValue();

    // When they send the beacon again with that cookie
    $this->withCredentials()->withUnencryptedCookie('lp_did', $cookie);
    viewBeacon($product->id)->assertNoContent();

    // Then only the first counted
    expect(array_sum(viewRefFields()))->toBe(1);
});

it('answers 204 and reports the failure when Redis is down, and a 404 still wins over an outage', function () {
    // Given a ViewCounters that throws, and a visible product
    Exceptions::fake();
    $this->app->instance(ViewCounters::class, new class extends ViewCounters
    {
        public function record(string $productId, string $deviceHash, string $ipHash, CarbonImmutable $now, string $source): int
        {
            throw new RuntimeException('redis is down');
        }
    });
    $product = viewVisibleProduct();

    // When the beacon arrives, and when it arrives for an unknown product
    viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();
    viewBeacon('01ARZ3NDEKTSV4RRFFQ69G5FAV', [], viewDevice('device-b'))->assertNotFound();

    // Then the failure was reported once (not for the 404) and nothing was counted
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis is down');
    Exceptions::assertReportedCount(1);
    expect(viewPendingHash())->toBe([]);
});

it('answers the 31st beacon of a device in a minute with 429 and counts nothing more', function () {
    // Given 30 beacons from one device for 30 different products (each counts once)
    $products = collect(range(1, 31))->map(fn () => viewVisibleProduct());
    foreach ($products->take(30) as $product) {
        viewBeacon($product->id, [], viewDevice('device-a'))->assertNoContent();
    }
    $before = viewPendingHash();

    // When the 31st arrives, and another device from the same address sends one
    $limited = viewBeacon($products[30]->id, [], viewDevice('device-a'));
    $other = viewBeacon($products[30]->id, [], viewDevice('device-b'));

    // Then the first is throttled with Retry-After and counted nothing, the second counts
    $limited->assertStatus(429)->assertHeader('Retry-After');
    expect(viewPendingHash())->toHaveKey("r|{$products[30]->id}|2026-10-21|direct")->and(viewPendingHash()["r|{$products[30]->id}|2026-10-21|direct"])->toBe('1');
    expect(array_sum(viewRefFields()))->toBe(31)->and(count($before))->toBe(60);
});

it('carries only its own limiter and no auth middleware on the route', function () {
    // Given the registered route
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/products/{product}/view');

    // When its middleware is gathered
    $middleware = $route->gatherMiddleware();

    // Then it carries exactly throttle:view (the api group adds its own at request time), and no auth guard
    expect(collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->values()->all())->toBe(['throttle:view']);
    expect($middleware)->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api');
});

it('answers 204 fast and reports once a minute when the beacon cannot reach Redis (closed port)', function () {
    // Given the beacon connection pointed at a closed port
    Exceptions::fake();
    // (the manager copies its config at boot, so build a new one whose beacon connection points at port 1)
    $config = config('database.redis');
    $config['views_beacon']['port'] = 1;
    $this->app->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', $config));
    Redis::clearResolvedInstance('redis');
    $product = viewVisibleProduct();

    // When three beacons arrive
    $started = microtime(true);
    foreach (['device-a', 'device-b', 'device-c'] as $device) {
        viewBeacon($product->id, [], viewDevice($device))->assertNoContent();
    }
    $elapsed = microtime(true) - $started;

    // Then every one answered 204 quickly, and the outage was reported once, not three times
    expect($elapsed)->toBeLessThan(1.0);
    Exceptions::assertReportedCount(1);

    // And a minute later it is reported again
    $this->travel(61)->seconds();
    viewBeacon($product->id, [], viewDevice('device-d'))->assertNoContent();
    Exceptions::assertReportedCount(2);
});
