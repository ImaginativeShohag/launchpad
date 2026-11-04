<?php

use App\Models\User;
use App\Support\DeviceHash;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::middleware('api')->get('api/v1/_limit/api-only', fn () => response()->noContent());
    foreach (['login', 'register', 'password-forgot', 'verification-resend', 'email-change', 'password-change', 'account-delete', 'google', 'avatar', 'admin-write', 'broadcast-auth', 'product-write', 'product-media', 'product-submit', 'vote', 'comment-create', 'comment-modify', 'follow', 'report', 'notifications-read', 'email-prefs', 'unsubscribe', 'search', 'view', 'dashboard', 'export-start', 'export-poll', 'export-download'] as $name) {
        Route::middleware(['api', 'throttle:'.$name])->post('api/v1/_limit/'.$name, fn () => response()->noContent());
    }
});

function hit(string $name, array $data = []): TestResponse
{
    return test()->postJson('/api/v1/_limit/'.$name, $data);
}

it('limits login to 5 per minute per ip and email', function () {
    // Given five attempts for one email
    foreach (range(1, 5) as $_) {
        hit('login', ['email' => 'a@x.com'])->assertNoContent();
    }

    // When a sixth arrives
    $sixth = hit('login', ['email' => 'a@x.com']);

    // Then it is throttled with a Retry-After header
    $sixth->assertStatus(429)->assertHeader('Retry-After');
});

it('gives a different email its own login bucket', function () {
    // Given a throttled email
    foreach (range(1, 6) as $_) {
        hit('login', ['email' => 'a@x.com']);
    }

    // When another email tries
    $other = hit('login', ['email' => 'b@x.com']);

    // Then it is not throttled
    $other->assertNoContent();
});

it('shares a login bucket across case and whitespace variants', function () {
    // Given attempts written in different ways
    hit('login', ['email' => 'A@X.com'])->assertNoContent();
    hit('login', ['email' => ' a@x.com '])->assertNoContent();
    hit('login', ['email' => 'a@x.com'])->assertNoContent();
    hit('login', ['email' => 'A@x.COM'])->assertNoContent();
    hit('login', ['email' => ' A@X.com'])->assertNoContent();

    // When one more variant arrives
    $sixth = hit('login', ['email' => 'a@x.com ']);

    // Then they all counted against the same bucket
    $sixth->assertStatus(429);
});

it('caps login at 20 per hour per email', function () {
    // Given 20 attempts spread over several minutes
    foreach (range(1, 20) as $i) {
        if ($i % 5 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('login', ['email' => 'a@x.com'])->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 21st arrives
    $response = hit('login', ['email' => 'a@x.com']);

    // Then the hourly cap stops it
    $response->assertStatus(429);
});

it('does not fail with a server error when email is an array', function () {
    // Given a malformed email input
    // When the throttled route is called
    $response = hit('login', ['email' => ['x']]);

    // Then the limiter copes and the request passes through
    $response->assertNoContent();
});

it('limits registration to 3 per minute per ip', function () {
    // Given three registrations
    foreach (range(1, 3) as $_) {
        hit('register')->assertNoContent();
    }

    // When a fourth arrives
    // Then it is throttled
    hit('register')->assertStatus(429)->assertHeader('Retry-After');
});

it('limits password reset requests to 3 per minute per ip and email', function () {
    // Given three requests for one address
    foreach (range(1, 3) as $_) {
        hit('password-forgot', ['email' => 'a@x.com'])->assertNoContent();
    }

    // When a fourth arrives
    // Then it is throttled
    hit('password-forgot', ['email' => 'a@x.com'])->assertStatus(429);
});

it('limits password reset requests to 20 per hour per ip across addresses', function () {
    // Given 20 requests for different addresses from one ip
    foreach (range(1, 20) as $i) {
        hit('password-forgot', ['email' => "u{$i}@x.com"])->assertNoContent();
    }

    // When the 21st arrives
    $response = hit('password-forgot', ['email' => 'u21@x.com']);

    // Then the ip cap stops it
    $response->assertStatus(429);
});

it('limits verification resend to 1 per minute', function () {
    // Given a signed-in user who just asked for an email
    Sanctum::actingAs(User::factory()->create());
    hit('verification-resend')->assertNoContent();

    // When they ask again immediately
    // Then they are throttled
    hit('verification-resend')->assertStatus(429)->assertHeader('Retry-After');
});

it('limits verification resend to 6 per hour', function () {
    // Given six requests a minute apart
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 6) as $_) {
        hit('verification-resend')->assertNoContent();
        $this->travel(61)->seconds();
    }

    // When a seventh arrives within the hour
    // Then the hourly cap stops it
    hit('verification-resend')->assertStatus(429);
});

it('limits email change requests to 3 per hour per user', function () {
    // Given a signed-in user with three requests
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 3) as $_) {
        hit('email-change')->assertNoContent();
    }

    // When a fourth arrives
    // Then it is throttled
    hit('email-change')->assertStatus(429);
});

it('limits password changes to 5 per minute per user', function () {
    // Given a signed-in user with five attempts
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 5) as $_) {
        hit('password-change')->assertNoContent();
    }

    // When a sixth arrives
    // Then it is throttled
    hit('password-change')->assertStatus(429)->assertHeader('Retry-After');
});

it('caps password changes at 20 per hour per user', function () {
    // Given 20 attempts spread over several minutes
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 20) as $i) {
        if ($i % 5 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('password-change')->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 21st arrives
    // Then the hourly cap stops it
    hit('password-change')->assertStatus(429);
});

it('limits account deletion to 5 per minute and 20 per hour per user', function () {
    // Given a signed-in user with five attempts
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 5) as $_) {
        hit('account-delete')->assertNoContent();
    }

    // When a sixth arrives
    // Then it is throttled
    hit('account-delete')->assertStatus(429)->assertHeader('Retry-After');

    // And the hourly cap stops the 21st
    foreach (range(1, 3) as $_) {
        $this->travel(61)->seconds();
        foreach (range(1, 5) as $_) {
            hit('account-delete');
        }
    }
    $this->travel(61)->seconds();
    hit('account-delete')->assertStatus(429);
});

it('limits the google routes to 20 per minute per ip', function () {
    // Given twenty requests from one ip
    foreach (range(1, 20) as $_) {
        hit('google')->assertNoContent();
    }

    // When a 21st arrives, and when another ip calls
    $extra = hit('google');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/_limit/google');

    // Then the first ip is throttled and the other has its own bucket
    $extra->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('caps the google routes at 120 per hour per ip', function () {
    // Given 120 requests spread over several minutes (never more than 20 per minute)
    foreach (range(1, 120) as $i) {
        if ($i % 20 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('google')->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 121st arrives, and when another ip calls
    $response = hit('google');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/_limit/google');

    // Then the hourly cap stops it for this ip only
    $response->assertStatus(429);
    $other->assertNoContent();
});

it('puts the google throttle on every real google route', function () {
    // Given the registered routes
    // When their middleware is inspected
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/auth/google/'))
        ->mapWithKeys(fn ($route) => [$route->methods()[0].' '.$route->uri() => $route->gatherMiddleware()]);

    // Then redirect, callback and token all use throttle:google
    expect($routes->keys()->sort()->values()->all())->toBe([
        'GET api/v1/auth/google/callback',
        'GET api/v1/auth/google/redirect',
        'POST api/v1/auth/google/token',
    ]);
    foreach ($routes as $middleware) {
        expect($middleware)->toContain('throttle:google');
    }
});

it('limits avatar changes to 10 per minute per user', function () {
    // Given a user with ten avatar requests
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    foreach (range(1, 10) as $_) {
        hit('avatar')->assertNoContent();
    }

    // When an eleventh arrives, and when another user calls
    $extra = hit('avatar');
    Sanctum::actingAs(User::factory()->create());
    $other = hit('avatar');

    // Then the first user is throttled and the other has their own bucket
    $extra->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('applies the avatar limiter to the avatar routes', function () {
    // Given the registered routes
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => $r->uri() === 'api/v1/me/avatar');

    // When their middleware is read
    // Then both POST and DELETE use throttle:avatar
    expect($routes)->toHaveCount(2);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('throttle:avatar');
    }
});

it('limits admin changes to 30 per minute per user', function () {
    // Given a signed-in user with thirty changes
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 30) as $_) {
        hit('admin-write')->assertNoContent();
    }

    // When a 31st arrives
    // Then it is throttled
    hit('admin-write')->assertStatus(429)->assertHeader('Retry-After');
});

it('caps admin changes at 200 per hour per user', function () {
    // Given 200 changes spread over several minutes
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 200) as $i) {
        if ($i % 30 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('admin-write')->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 201st arrives
    // Then the hourly cap stops it
    hit('admin-write')->assertStatus(429);
});

it('applies the admin-write limiter to the mutating admin routes', function () {
    // Given the registered routes
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array($r->uri(), ['api/v1/admin/users/{user}/role', 'api/v1/admin/settings/{key}'], true));

    // When their middleware is read
    // Then both PATCH routes use throttle:admin-write
    expect($routes)->toHaveCount(2);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('throttle:admin-write');
    }
});

it('limits broadcast auth to 30 per minute per user', function () {
    // Given a signed-in user with thirty requests
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 30) as $_) {
        hit('broadcast-auth')->assertNoContent();
    }

    // When a 31st arrives, and when another user calls
    $extra = hit('broadcast-auth');
    Sanctum::actingAs(User::factory()->create());
    $other = hit('broadcast-auth');

    // Then the first user is throttled and the other has their own bucket
    $extra->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('does not fail with a server error when broadcast auth input is an array', function () {
    // Given a limiter keyed on the member, never on request input
    // When the throttled route gets array payloads
    $response = hit('broadcast-auth', ['channel_name' => ['x'], 'socket_id' => ['y']]);

    // Then the limiter copes and the request passes through
    $response->assertNoContent();
});

it('keys the broadcast-auth limiter on the member and falls back to the ip', function () {
    // Given anonymous callers from two ips
    foreach (range(1, 30) as $_) {
        hit('broadcast-auth')->assertNoContent();
    }

    // When the 31st arrives from the same ip and one from another ip
    $extra = hit('broadcast-auth');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/_limit/broadcast-auth');

    // Then the first ip is throttled and the other has its own bucket
    $extra->assertStatus(429);
    $other->assertNoContent();
});

it('caps login at 30 per minute per ip across many emails', function () {
    // Given 30 attempts for 30 different emails from one ip (no per-email bucket is hit)
    foreach (range(1, 30) as $i) {
        hit('login', ['email' => "u{$i}@x.com"])->assertNoContent();
    }

    // When a 31st email arrives, and when another ip calls
    $extra = hit('login', ['email' => 'u31@x.com']);
    $other = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/_limit/login', ['email' => 'u32@x.com']);

    // Then the first ip is throttled and the other has its own bucket
    $extra->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('declares the login limits as 5/min per ip+email, 20/hour per email and 30/min per ip', function () {
    // Given the registered login limiter
    $request = Request::create('/x', 'POST', ['email' => ' A@X.com '], server: ['REMOTE_ADDR' => '1.2.3.4']);

    // When it resolves for a request
    $limits = collect(RateLimiter::limiter('login')($request))
        ->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds, $l->key])->all();

    // Then all three buckets are pinned with array-safe keys
    expect($limits)->toBe([
        [5, 60, 'login:1.2.3.4|a@x.com'],
        [20, 3600, 'login-hour:a@x.com'],
        [30, 60, 'login-ip:1.2.3.4'],
    ]);
});

it('limits the api group to 60 per minute per bearer token', function () {
    // Given 60 requests with one bearer token
    foreach (range(1, 60) as $_) {
        $this->withToken('1|first-token')->getJson('/api/v1/_limit/api-only')->assertNoContent();
    }

    // When a 61st arrives with the same token, and when a different token calls from the same ip
    $extra = $this->withToken('1|first-token')->getJson('/api/v1/_limit/api-only');
    $other = $this->withToken('2|second-token')->getJson('/api/v1/_limit/api-only');

    // Then the first token is throttled and the other token has its own bucket
    $extra->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('keys the api limiter on a hash of the bearer token, never the raw token', function () {
    // Given a request carrying a bearer token
    $token = '42|SuperSecretTokenValue';
    $request = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);

    // When the api limiter resolves
    $limits = collect(RateLimiter::limiter('api')($request));

    // Then no key contains the raw token or its secret part, and the token bucket is a 60/min hash
    foreach ($limits as $limit) {
        expect($limit->key)->not->toContain($token)->not->toContain('SuperSecretTokenValue');
    }
    $tokenLimit = $limits->first(fn (Limit $l) => str_starts_with($l->key, 'api-token:'));
    expect($tokenLimit->maxAttempts)->toBe(60)
        ->and($tokenLimit->key)->toMatch('/^api-token:[a-f0-9]{64}$/');
});

it('gives different bearer tokens different api keys and the same token the same key', function () {
    // Given three requests, two sharing a token
    $make = fn (string $token) => Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $key = fn (Request $r) => collect(RateLimiter::limiter('api')($r))->first(fn (Limit $l) => str_starts_with($l->key, 'api-token:'))->key;

    // When the keys are computed
    // Then equal tokens match and different tokens differ
    expect($key($make('a')))->toBe($key($make('a')))->not->toBe($key($make('b')));
});

it('falls back to the ip for the api limiter without a bearer token', function () {
    // Given a guest request and an empty bearer header
    $guest = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);
    $empty = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_AUTHORIZATION' => 'Bearer ']);

    // When the limiter resolves
    $keys = fn (Request $r) => collect(Arr::wrap(RateLimiter::limiter('api')($r)))->map(fn (Limit $l) => [$l->maxAttempts, $l->key])->all();

    // Then both use the plain per-ip 60/min bucket
    expect($keys($guest))->toBe([[60, '1.2.3.4']])->and($keys($empty))->toBe([[60, '1.2.3.4']]);
});

it('still caps one ip across rotating bearer tokens so garbage tokens cannot dodge the api limit', function () {
    // Given 300 requests from one ip, each with a different junk token (a fresh token bucket each time)
    foreach (range(1, 300) as $i) {
        $this->withToken("junk-{$i}")->getJson('/api/v1/_limit/api-only')->assertNoContent();
    }

    // When the 301st arrives with yet another token
    // Then the per-ip backstop stops it
    $this->withToken('junk-301')->getJson('/api/v1/_limit/api-only')->assertStatus(429);
});

it('pins the product limiters: per member, minute and hour keys apart, array-safe', function (string $name, int $perMinute, int $perHour) {
    // Given two members and a request carrying hostile input
    $a = User::factory()->create();
    $b = User::factory()->create();
    $request = fn (User $user) => tap(Request::create('/x', 'POST', ['email' => ['x'], 'name' => ['y']]), fn (Request $r) => $r->setUserResolver(fn () => $user));

    // When the limiter callbacks run
    $forA = RateLimiter::limiter($name)($request($a));
    $forB = RateLimiter::limiter($name)($request($b));

    // Then there is one minute and one hour limit, the keys differ between the two windows and between members
    expect(collect($forA)->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds])->all())->toBe([[$perMinute, 60], [$perHour, 3600]]);
    expect($forA[0]->key)->not->toBe($forA[1]->key)->and($forA[0]->key)->not->toBe($forB[0]->key)->and($forA[0]->key)->toContain($a->id);
    expect($perMinute)->toBeLessThan(60);
})->with([['product-write', 30, 300], ['product-media', 10, 60], ['product-submit', 5, 20], ['vote', 30, 300], ['comment-create', 6, 60], ['comment-modify', 10, 60], ['follow', 20, 200], ['report', 5, 30], ['notifications-read', 30, 300], ['email-prefs', 20, 100]]);

it('pins the unsubscribe limiter: per IP, minute and hour keys apart, below the api bucket', function () {
    // Given two requests from different addresses and one with hostile input
    $request = fn (string $ip) => Request::create('/x', 'POST', ['user' => ['x'], 'type' => ['y']], server: ['REMOTE_ADDR' => $ip]);

    // When the limiter callback runs
    $forA = RateLimiter::limiter('unsubscribe')($request('1.2.3.4'));
    $forB = RateLimiter::limiter('unsubscribe')($request('5.6.7.8'));

    // Then it is 20 a minute and 100 an hour per address, with separate keys
    expect(collect($forA)->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds])->all())->toBe([[20, 60], [100, 3600]]);
    expect($forA[0]->key)->not->toBe($forA[1]->key)->and($forA[0]->key)->not->toBe($forB[0]->key)->and($forA[0]->key)->toContain('1.2.3.4');
});

it('pins the search limiter: 45 a minute per viewer and 90 a minute per IP backstop, below the api bucket, never keyed on the query', function () {
    // Given a guest request and a bearer request carrying hostile input
    $guest = Request::create('/x', 'GET', ['q' => ['x']], server: ['REMOTE_ADDR' => '1.2.3.4']);
    $bearer = Request::create('/x', 'GET', ['q' => 'launch'], server: ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_AUTHORIZATION' => 'Bearer 7|TokenSecretPart']);

    // When the limiter callbacks run
    $pairs = fn (Request $r) => collect(Arr::wrap(RateLimiter::limiter('search')($r)))->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds, $l->key]);

    // Then a guest has the 45/min IP bucket, a bearer has a 45/min hash bucket and a 90/min IP backstop, all under the api buckets
    expect($pairs($guest)->all())->toBe([[45, 60, 'search-ip:1.2.3.4']]);
    $forBearer = $pairs($bearer);
    expect($forBearer->map(fn ($p) => [$p[0], $p[1]])->all())->toBe([[45, 60], [90, 60]]);
    expect($forBearer[0][2])->toMatch('/^search-token:[a-f0-9]{64}$/')->not->toContain('TokenSecretPart')->and($forBearer[1][2])->toBe('search-ip-backstop:1.2.3.4');
    expect(45)->toBeLessThan(60)->and(90)->toBeLessThan(300);
});

it('throttles search at 45 per minute per ip through the named limiter', function () {
    // Given 45 hits from one address
    foreach (range(1, 45) as $_) {
        hit('search')->assertNoContent();
    }

    // When the 46th arrives
    // Then it is throttled with Retry-After
    hit('search')->assertStatus(429)->assertHeader('Retry-After');
});

it('pins the view limiter: 30 a minute and 600 an hour per device (else per IP), 120 a minute per IP, on separate keys', function () {
    // Given a request with a well-formed device header, one with a malformed header, and one with neither
    $server = ['REMOTE_ADDR' => '1.2.3.4'];
    $withDevice = Request::create('/x', 'POST', server: $server + ['HTTP_X_DEVICE_ID' => 'device-abcdef0123456789']);
    $badDevice = Request::create('/x', 'POST', server: $server + ['HTTP_X_DEVICE_ID' => 'short']);
    $bare = Request::create('/x', 'POST', ['referrer' => ['x']], server: $server);
    $pairs = fn (Request $r) => collect(Arr::wrap(RateLimiter::limiter('view')($r)))->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds, $l->key]);

    // When the limiter callbacks run
    // Then each has 30/min, 600/h and the 120/min IP backstop on three distinct keys
    foreach ([$withDevice, $badDevice, $bare] as $r) {
        expect($pairs($r)->map(fn ($p) => [$p[0], $p[1]])->all())->toBe([[30, 60], [600, 3600], [120, 60]]);
        expect($pairs($r)->pluck(2)->unique()->count())->toBe(3);
    }
    // And a presented device is keyed by its HMAC (never the raw id), a missing or malformed one falls back to the IP, nothing reads the body
    expect($pairs($withDevice)[0][2])->toMatch('/^view:[a-f0-9]{64}$/')->not->toContain('device-abcdef')->and($pairs($withDevice)[2][2])->toBe('view-ip:'.DeviceHash::viewIp($withDevice));
    $ipHash = DeviceHash::viewIp($bare);
    expect($ipHash)->toMatch('/^[a-f0-9]{64}$/')->and($pairs($badDevice)[0][2])->toBe('view:'.$ipHash)->and($pairs($bare)[0][2])->toBe('view:'.$ipHash)->and($pairs($bare)[1][2])->toBe('view-hour:'.$ipHash);
    foreach ([$withDevice, $badDevice, $bare] as $r) {
        expect(implode(' ', $pairs($r)->pluck(2)->all()))->not->toContain('1.2.3.4');
    }
    expect(30)->toBeLessThan(60);
});

it('throttles one device at 30 beacons a minute and leaves another device on the same address free', function () {
    // Given 30 beacons from one device through the named limiter
    foreach (range(1, 30) as $_) {
        test()->postJson('/api/v1/_limit/view', [], ['X-Device-Id' => 'device-one-0000001'])->assertNoContent();
    }

    // When the 31st arrives, and another device from the same address
    $limited = test()->postJson('/api/v1/_limit/view', [], ['X-Device-Id' => 'device-one-0000001']);
    $other = test()->postJson('/api/v1/_limit/view', [], ['X-Device-Id' => 'device-two-0000002']);

    // Then the first is throttled with Retry-After and the second is not
    $limited->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('throttles requests without a device id at 30 a minute per address', function () {
    // Given 30 cookie-less beacons from one address
    foreach (range(1, 30) as $_) {
        hit('view')->assertNoContent();
    }

    // When the 31st arrives
    // Then it is throttled
    hit('view')->assertStatus(429)->assertHeader('Retry-After');
});

it('stops many devices behind one address at 120 a minute with the per-IP backstop', function () {
    // Given a route with only the view limiter (the api group's own 60/min per address would stop this first)
    Route::middleware('throttle:view')->post('api/v1/_limit/view-bare', fn () => response()->noContent());

    // When 120 distinct devices from one address are admitted
    foreach (range(1, 120) as $i) {
        test()->postJson('/api/v1/_limit/view-bare', [], ['X-Device-Id' => 'device-'.str_pad((string) $i, 10, '0', STR_PAD_LEFT)])->assertNoContent();
    }

    // Then the 121st is throttled although its own device bucket is empty
    test()->postJson('/api/v1/_limit/view-bare', [], ['X-Device-Id' => 'device-9999999999'])->assertStatus(429)->assertHeader('Retry-After');
});

it('throttles product submissions at 5 per minute per member', function () {
    // Given a member with five submissions
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 5) as $_) {
        hit('product-submit')->assertNoContent();
    }

    // When a sixth arrives
    // Then it is throttled
    hit('product-submit')->assertStatus(429)->assertHeader('Retry-After');
});

it('throttles comment creation at 6 per minute per member, independent of edits and deletes', function () {
    // Given a member who created six comments in a minute
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 6) as $_) {
        hit('comment-create')->assertNoContent();
    }

    // When a seventh create arrives, and an edit/delete from the same member
    $seventh = hit('comment-create');
    $modify = hit('comment-modify');

    // Then the create bucket is spent (with Retry-After) and the modify bucket is separate
    $seventh->assertStatus(429)->assertHeader('Retry-After');
    $modify->assertNoContent();
});

it('throttles comment edits and deletes at 10 per minute per member in one shared bucket', function () {
    // Given a member who made ten modifications
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 10) as $_) {
        hit('comment-modify')->assertNoContent();
    }

    // When an eleventh arrives, and another member calls
    $eleventh = hit('comment-modify');
    Sanctum::actingAs(User::factory()->create());
    $other = hit('comment-modify');

    // Then the first member is throttled and the other has their own bucket
    $eleventh->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('caps comment creation at 60 per hour per member', function () {
    // Given 60 creates spread over several minutes (never more than 6 per minute)
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 60) as $i) {
        if ($i % 6 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('comment-create')->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 61st arrives
    // Then the hourly cap stops it
    hit('comment-create')->assertStatus(429);
});

it('throttles follows and unfollows at 20 per minute per member in one shared bucket', function () {
    // Given a member who made twenty follow calls in a minute
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 20) as $_) {
        hit('follow')->assertNoContent();
    }

    // When a 21st arrives, and another member calls
    $twentyFirst = hit('follow');
    Sanctum::actingAs(User::factory()->create());
    $other = hit('follow');

    // Then the first member is throttled with Retry-After and the other has their own bucket
    $twentyFirst->assertStatus(429)->assertHeader('Retry-After');
    $other->assertNoContent();
});

it('caps follows at 200 per hour per member', function () {
    // Given 200 follow calls spread over several minutes (never more than 20 per minute)
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 200) as $i) {
        if ($i % 20 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('follow')->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 201st arrives
    // Then the hourly cap stops it
    hit('follow')->assertStatus(429);
});

it('throttles reports at 5 per minute per member, independent of follows', function () {
    // Given a member who sent five reports in a minute
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 5) as $_) {
        hit('report')->assertNoContent();
    }

    // When a sixth arrives, and a follow call from the same member
    $sixth = hit('report');
    $follow = hit('follow');

    // Then the report bucket is spent (with Retry-After) and the follow bucket is separate
    $sixth->assertStatus(429)->assertHeader('Retry-After');
    $follow->assertNoContent();
});

it('caps reports at 30 per hour per member', function () {
    // Given 30 reports spread over several minutes (never more than 5 per minute)
    Sanctum::actingAs(User::factory()->create());
    foreach (range(1, 30) as $i) {
        if ($i % 5 === 1 && $i > 1) {
            $this->travel(61)->seconds();
        }
        hit('report')->assertNoContent();
    }
    $this->travel(61)->seconds();

    // When the 31st arrives
    // Then the hourly cap stops it
    hit('report')->assertStatus(429);
});

it('gives IPv6 callers of one /64 the same view buckets, another /64 its own, and treats an IPv4-mapped address as IPv4', function () {
    // Given requests from addresses inside one /64, a neighbouring /64, an IPv4 address and its IPv4-mapped form
    $keyOf = fn (string $ip) => RateLimiter::limiter('view')(Request::create('/x', 'POST', server: ['REMOTE_ADDR' => $ip]))[0]->key;

    // When the limiter keys are computed
    // Then the same /64 shares a key, another /64 does not, and mapped IPv4 equals plain IPv4
    expect($keyOf('2001:db8:1:2::1'))->toBe($keyOf('2001:db8:1:2:ffff:ffff:ffff:ffff'));
    expect($keyOf('2001:db8:1:2::1'))->not->toBe($keyOf('2001:db8:1:3::1'));
    expect($keyOf('::ffff:1.2.3.4'))->toBe($keyOf('1.2.3.4'))->and($keyOf('1.2.3.4'))->not->toBe($keyOf('1.2.3.5'));
});

it('pins the dashboard limiter: 30 a minute per member (the IP for a guest), below the api bucket, never keyed on the query or the product', function () {
    // Given a signed-in member and a guest, both sending hostile query input
    $member = User::factory()->create();
    $asMember = Request::create('/x', 'GET', ['from' => ['x']], server: ['REMOTE_ADDR' => '1.2.3.4']);
    $asMember->setUserResolver(fn () => $member);
    $guest = Request::create('/x', 'GET', ['granularity' => 'hour'], server: ['REMOTE_ADDR' => '1.2.3.4']);

    // When the limiter callbacks run
    $pairs = fn (Request $r) => collect(Arr::wrap(RateLimiter::limiter('dashboard')($r)))->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds, $l->key]);

    // Then it is one 30/min bucket keyed on the member (the address without one), under the api bucket's 60
    expect($pairs($asMember)->all())->toBe([[30, 60, 'dashboard:'.$member->getKey()]])
        ->and($pairs($guest)->all())->toBe([[30, 60, 'dashboard:1.2.3.4']])
        ->and(30)->toBeLessThan(60);
});

it('throttles the dashboard probe at 30 per minute through the named limiter', function () {
    // Given 30 hits from one address
    foreach (range(1, 30) as $_) {
        hit('dashboard')->assertNoContent();
    }

    // When the 31st arrives
    // Then it is throttled with Retry-After
    hit('dashboard')->assertStatus(429)->assertHeader('Retry-After');
});

it('pins the export limiters: 3 an hour and 10 a day to start (per member only), 30 a minute to poll, 20 an hour to download', function () {
    // Given a signed-in member and a guest on one address, both on a product route with hostile input
    $member = User::factory()->create();
    $asMember = Request::create('/api/v1/me/products/PRODUCT-A/exports', 'POST', ['product' => ['x']], server: ['REMOTE_ADDR' => '1.2.3.4']);
    $asMember->setUserResolver(fn () => $member);
    $guest = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']);
    $pairs = fn (string $name, Request $r) => collect(Arr::wrap(RateLimiter::limiter($name)($r)))->map(fn (Limit $l) => [$l->maxAttempts, $l->decaySeconds, $l->key])->all();

    // When the limiter callbacks run
    // Then the start limiter has an hour and a day bucket per member (the address for a guest) and never depends on the product
    expect($pairs('export-start', $asMember))->toBe([[3, 3600, 'export-start-hour:'.$member->getKey()], [10, 86400, 'export-start-day:'.$member->getKey()]]);
    expect($pairs('export-start', $guest))->toBe([[3, 3600, 'export-start-hour:1.2.3.4'], [10, 86400, 'export-start-day:1.2.3.4']]);

    // And polling is one 30 a minute bucket, below the api bucket's 60
    expect($pairs('export-poll', $asMember))->toBe([[30, 60, 'export-poll:'.$member->getKey()]])->and(30)->toBeLessThan(60);

    // And downloads are 20 an hour per member
    expect($pairs('export-download', $asMember))->toBe([[20, 3600, 'export-download:'.$member->getKey()]]);
});

it('throttles starting an export at 3 per hour and polling at 30 per minute through the named limiters', function () {
    // Given three starts from one address
    foreach (range(1, 3) as $_) {
        hit('export-start')->assertNoContent();
    }

    // When a fourth arrives
    // Then it is throttled with Retry-After, and the poll bucket is independent
    hit('export-start')->assertStatus(429)->assertHeader('Retry-After');
    foreach (range(1, 30) as $_) {
        hit('export-poll')->assertNoContent();
    }
    hit('export-poll')->assertStatus(429)->assertHeader('Retry-After');
});

it('caps starting exports at 10 per day per member', function () {
    // Given ten starts spread over ten hours (never more than 3 an hour)
    foreach (range(1, 10) as $i) {
        if ($i % 3 === 1 && $i > 1) {
            $this->travel(61)->minutes();
        }
        hit('export-start')->assertNoContent();
    }
    $this->travel(61)->minutes();

    // When the 11th arrives inside the day
    // Then the daily cap stops it
    hit('export-start')->assertStatus(429);
});
