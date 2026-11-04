<?php

use App\Actions\Dashboard\DashboardCache;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(dashNow());
    config(['launchpad.dashboard.cache_seconds' => 30]);
});

/** @return list<string> the keys currently held by the array cache store */
function dashCacheKeys(): array
{
    $store = Cache::getStore();
    expect($store)->toBeInstanceOf(ArrayStore::class);
    $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);

    return array_values(array_filter(array_keys($storage), fn ($k) => str_contains((string) $k, 'dash:')));
}

it('serves the second identical read from the cache and a changed query as a miss', function () {
    // Given a maker with a product that has a bucket
    $product = dashMakerProduct();
    dashBucket($product, '2026-10-21 10:00:00', 1, 1);

    // When the same series is read twice, then with another query
    $first = dashStatsQueries(fn () => dashApi('me', $product->id, 'series')->assertOk());
    $second = dashStatsQueries(fn () => dashApi('me', $product->id, 'series')->assertOk());
    $other = dashStatsQueries(fn () => dashApi('me', $product->id, 'series', ['granularity' => 'day'])->assertOk());

    // Then the second read touched no stats table, and the changed query did
    expect($first)->toHaveCount(2)->and($second)->toBe([])->and($other)->toHaveCount(2);
});

it('really shows the cached payload for the TTL and the database after it is bypassed', function () {
    // Given a cached summary
    $product = dashMakerProduct(DASH_DAY, ['votes_count' => 1]);
    dashApi('me', $product->id)->assertOk()->assertJsonPath('data.votes', 1);

    // When the counter changes
    $product->forceFill(['votes_count' => 5])->save();

    // Then the cached value is served until the TTL is 0
    dashApi('me', $product->id)->assertJsonPath('data.votes', 1);
    config(['launchpad.dashboard.cache_seconds' => 0]);
    dashApi('me', $product->id)->assertJsonPath('data.votes', 5);
});

it('queries on every read when the TTL is 0', function () {
    // Given the cache bypassed
    config(['launchpad.dashboard.cache_seconds' => 0]);
    $product = dashMakerProduct();

    // When read twice
    $first = dashStatsQueries(fn () => dashApi('me', $product->id, 'series')->assertOk());
    $second = dashStatsQueries(fn () => dashApi('me', $product->id, 'series')->assertOk());

    // Then both read the database and nothing is stored
    expect($first)->toHaveCount(2)->and($second)->toHaveCount(2)->and(dashCacheKeys())->toBe([]);
});

it('keys the entry by product and endpoint only, never by member or personal data', function () {
    // Given a maker reading three endpoints
    $maker = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);
    dashApi('me', $product->id)->assertOk();
    dashApi('me', $product->id, 'rank')->assertOk();
    dashApi('me', $product->id, 'series', ['from' => '2026-10-21'])->assertOk();

    // When the stored keys are inspected
    $keys = dashCacheKeys();

    // Then each holds the product id and the endpoint, and nothing of the member
    expect($keys)->toHaveCount(3);
    foreach ($keys as $key) {
        expect($key)->toMatch('/^(.*)dash:'.$product->id.':(summary|rank|series):[a-f0-9]{40}$/')->not->toContain($maker->id)->not->toContain($maker->email)->not->toContain($maker->handle);
    }
});

it('never shares an entry between two products', function () {
    // Given a maker with two products with different votes
    $maker = signedInMember();
    $a = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'votes_count' => 3]);
    $b = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'votes_count' => 9]);

    // When both summaries are read
    $readA = dashApi('me', $a->id)->assertOk();
    $readB = dashApi('me', $b->id)->assertOk();

    // Then each shows its own numbers
    expect($readA->json('data.votes'))->toBe(3)->and($readB->json('data.votes'))->toBe(9)->and(dashCacheKeys())->toHaveCount(2);
});

it('shares one entry between the maker and staff, and still authorises every read first', function () {
    // Given a maker who warmed the entry
    $maker = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);
    $makerBody = dashApi('me', $product->id)->assertOk()->getContent();

    // When staff read the same product, and a stranger tries the cached product
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $staff = dashStatsQueries(fn () => dashApi('staff', $product->id)->assertOk());
    $staffBody = dashApi('staff', $product->id)->getContent();
    Sanctum::actingAs(User::factory()->create());
    $stranger = dashApi('me', $product->id);

    // Then staff got the identical payload from the same entry, and the stranger the uniform 404
    expect($staffBody)->toBe($makerBody)->and($staff)->toBe([])->and(dashCacheKeys())->toHaveCount(1);
    $stranger->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('reports a failing cache store and still answers', function () {
    // Given a cache whose store throws on every call
    Cache::extend('dash-broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('cache down');
        }
    }));
    config(['cache.stores.dash-broken' => ['driver' => 'dash-broken'], 'cache.default' => 'dash-broken']);
    Cache::purge('dash-broken');
    Exceptions::fake();
    $product = dashMakerProduct();
    dashBucket($product, '2026-10-21 10:00:00', 2, 3);

    // When a series is read
    $response = dashApi('me', $product->id, 'series');

    // Then the data still arrives and the failure was reported
    $response->assertOk()->assertJsonPath('data.points.10.votes', 2);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not compute twice when the compute itself fails', function () {
    // Given a compute that throws
    $calls = 0;

    // When it is asked through the cache
    try {
        app(DashboardCache::class)->remember('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'series', [], function () use (&$calls): array {
            $calls++;
            throw new LogicException('boom');
        });
    } catch (LogicException) {
    }

    // Then it ran once and the error was not swallowed as a cache failure
    expect($calls)->toBe(1);
});

it('does not serve a cached payload across a status change such as a take-down', function () {
    // Given a maker whose live product was read (and cached)
    $maker = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);
    dashApi('me', $product->id)->assertOk()->assertJsonPath('data.taken_down', false);

    // When an admin takes it down
    $product->forceFill(['status' => ProductStatus::TakenDown, 'taken_down_at' => now()])->save();

    // Then the next read shows it at once, not after the TTL
    dashApi('me', $product->id)->assertOk()->assertJsonPath('data.taken_down', true)->assertJsonPath('data.status', 'taken_down');
});
