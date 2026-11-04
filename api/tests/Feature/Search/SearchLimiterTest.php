<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => searchLive('Quokka Tool'));

/** @return list<array{0: int, 1: string}> the search limiter's [maxAttempts, key] pairs for a request */
function searchLimits(Request $request): array
{
    return collect(Arr::wrap(RateLimiter::limiter('search')($request)))->map(fn (Limit $limit) => [$limit->maxAttempts, $limit->key])->all();
}

it('pins the search limiter: 45 a minute per viewer, 90 a minute per IP backstop, both below the api group', function () {
    // Given a guest request and a bearer request
    $guest = Request::create('/x', 'GET', ['q' => 'anything'], server: ['REMOTE_ADDR' => '1.2.3.4']);
    $bearer = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_AUTHORIZATION' => 'Bearer 42|SuperSecretTokenValue']);

    // When the limiter resolves
    $forGuest = searchLimits($guest);
    $forBearer = searchLimits($bearer);

    // Then a guest has one 45/min IP bucket; a token has a 45/min hash bucket and a 90/min IP backstop; no raw token in a key
    expect($forGuest)->toBe([[45, 'search-ip:1.2.3.4']]);
    expect($forBearer[0][0])->toBe(45)->and($forBearer[0][1])->toMatch('/^search-token:[a-f0-9]{64}$/')->and($forBearer[1])->toBe([90, 'search-ip-backstop:1.2.3.4']);
    foreach ($forBearer as [, $key]) {
        expect($key)->not->toContain('SuperSecretTokenValue')->not->toContain('42|');
    }
    // 60 and 300 are the api group's buckets (pinned in RateLimitersTest)
    expect($forGuest[0][0])->toBeLessThan(60)->and($forBearer[0][0])->toBeLessThan(60)->and($forBearer[1][0])->toBeLessThan(300)->and($forBearer[1][0])->toBeLessThanOrEqual(2 * $forGuest[0][0]);
    foreach (array_merge($forGuest, $forBearer) as [, $key]) {
        expect($key)->not->toContain('anything');
    }
});

it('throttles the 46th search from one IP with Retry-After, and leaves another IP alone', function () {
    // Given 45 searches (different queries: the key never contains q) from one address
    foreach (range(1, 45) as $i) {
        searchApi('q=quokka'.($i % 2 === 0 ? 'x' : ''))->assertOk();
    }

    // When the 46th arrives, and one from another address
    $over = searchApi('q=other');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])->getJson('/api/v1/search?q=quokka');

    // Then the first is throttled and the second is fine
    $over->assertStatus(429)->assertHeader('Retry-After');
    $other->assertOk();
});

it('gives one bearer token 45 a minute, and a second token from the same IP its own bucket', function () {
    // Given 45 searches with one token
    foreach (range(1, 45) as $_) {
        $this->withToken('1|first-token')->getJson('/api/v1/search?q=quokka')->assertOk();
    }

    // When the 46th arrives with it, and one with another token
    $over = $this->withToken('1|first-token')->getJson('/api/v1/search?q=quokka');
    $other = $this->withToken('2|second-token')->getJson('/api/v1/search?q=quokka');

    // Then the first token is throttled and the other has its own bucket
    $over->assertStatus(429)->assertHeader('Retry-After');
    $other->assertOk();
});

it('caps one IP at 90 a minute across rotating junk bearer tokens', function () {
    // Given 90 searches from one IP, each with a fresh junk token (each below the 45 token bucket)
    foreach (range(1, 90) as $i) {
        $this->withToken("junk-{$i}")->getJson('/api/v1/search?q=quokka')->assertOk();
    }

    // When the 91st arrives with yet another token
    // Then the per-IP backstop stops it
    $this->withToken('junk-91')->getJson('/api/v1/search?q=quokka')->assertStatus(429)->assertHeader('Retry-After');
});

it('carries throttle:search as its only named throttle and none of the three auth guards', function () {
    // Given the registered search route
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/search');

    // When its middleware is collected
    $named = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->values()->all();

    // Then it is exactly the search limiter, and the route is public
    expect($named)->toBe(['throttle:search']);
    expect($route->gatherMiddleware())->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api');
    expect($route->methods())->toBe(['GET', 'HEAD']);
});

it('keeps the guest bucket and the bearer backstop of one IP independent', function () {
    // Given 45 guest searches from one IP (the guest bucket is full)
    foreach (range(1, 45) as $_) {
        searchApi('q=quokka')->assertOk();
    }

    // When a bearer request from the same IP arrives, and a 46th guest one
    $guest = searchApi('q=quokka');
    $bearer = $this->withToken('5|fresh-token')->getJson('/api/v1/search?q=quokka');

    // Then the bearer request has its own counters, and the guest is throttled
    $bearer->assertOk();
    $guest->assertStatus(429);
});
