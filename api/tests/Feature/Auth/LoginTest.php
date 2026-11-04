<?php

use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

it('signs a member in from the web app', function () {
    // Given a registered member
    $user = User::factory()->create(['email' => 'amina@example.com']);

    // When they log in with the right password
    $response = spa()->postJson('/api/v1/auth/login', [
        'email' => 'Amina@Example.com', 'password' => 'correct-horse-battery',
    ]);

    // Then they are signed in, the login is recorded and last_login_at is set
    $response->assertOk()->assertJsonPath('data.id', $user->id);
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::LoginSucceeded)->exists())->toBeTrue();
});

it('gives the same error for a wrong password, an unknown email, a suspended member and a Google-only account', function () {
    // Given four different situations
    User::factory()->create(['email' => 'amina@example.com']);
    User::factory()->suspended()->create(['email' => 'banned@example.com']);
    User::factory()->withoutPassword()->create(['email' => 'google@example.com']);

    // When each tries to log in with a password
    $wrong = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope']);
    $unknown = spa()->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope-nope-nope']);
    $suspended = spa()->postJson('/api/v1/auth/login', ['email' => 'banned@example.com', 'password' => 'correct-horse-battery']);
    $googleOnly = spa()->postJson('/api/v1/auth/login', ['email' => 'google@example.com', 'password' => 'whatever-password']);

    // Then all four are indistinguishable, nobody is signed in, and each failure is recorded
    foreach ([$wrong, $unknown, $suspended, $googleOnly] as $response) {
        $response->assertUnprocessable();
        expect($response->json('message'))->toBe($wrong->json('message'));
        expect($response->json('errors'))->toBe($wrong->json('errors'));
    }
    $this->assertGuest();
    expect(AuthEvent::query()->where('event', AuthEventType::LoginFailed)->count())->toBe(4);
});

it('throttles repeated attempts for the same email', function () {
    // Given five failed attempts in a minute
    foreach (range(1, 5) as $i) {
        spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope'])
            ->assertUnprocessable();
    }

    // When a sixth arrives
    $response = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope']);

    // Then it is throttled
    $response->assertTooManyRequests();
});

it('refuses browser login from a client without a session', function () {
    // Given a client that is not the React site (no Origin header)
    User::factory()->create(['email' => 'amina@example.com']);

    // When it posts to /auth/login
    $response = $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery']);

    // Then it is told to use /auth/token
    $response->assertStatus(400);
});

it('stores only keyed hashes of the IP address and device id', function () {
    // Given a member and a known device cookie
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $device = Str::lower((string) Str::ulid());

    // When they log in from that device
    spa()->withCredentials()->withUnencryptedCookie('lp_did', $device)
        ->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery'])
        ->assertOk();

    // Then the recorded event holds HMACs, never the raw values
    $event = AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::LoginSucceeded)->firstOrFail();
    $key = config('launchpad.hmac_key');
    expect($event->device_hash)->toBe(hash_hmac('sha256', $device, $key));
    expect($event->ip_hash)->toBe(hash_hmac('sha256', '127.0.0.1', $key));
    expect($event->device_hash)->not->toContain($device);
});

it('signs a member out and records it', function () {
    // Given a signed-in member
    $user = User::factory()->create();
    $this->actingAs($user);
    $sessionId = Str::random(40);

    // When they log out
    $response = spa()->withCredentials()->withUnencryptedCookie(config('session.cookie'), $sessionId)
        ->postJson('/api/v1/auth/logout');

    // Then the session is gone and the logout is recorded
    // (the web guard is checked explicitly: auth:sanctum switches the default guard and caches its own user)
    $response->assertNoContent();
    $this->assertGuest('web');
    expect(session()->getId())->not->toBe($sessionId);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::Logout)->exists())->toBeTrue();
});

it('rejects array, object, null and empty login fields with 422, never 500', function (string $field, mixed $value) {
    // Given a member and a payload where one field has the wrong shape
    User::factory()->create(['email' => 'amina@example.com']);
    $payload = array_merge(['email' => 'amina@example.com', 'password' => 'correct-horse-battery'], [$field => $value]);

    // When it is posted
    $response = spa()->postJson('/api/v1/auth/login', $payload);

    // Then validation rejects it and nobody is signed in
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    $this->assertGuest();
})->with(function () {
    foreach (['email', 'password'] as $field) {
        foreach ([[], ['a'], ['k' => 'v'], null, ''] as $i => $value) {
            yield "$field #$i" => [$field, $value];
        }
    }
});

it('rejects a non-boolean remember flag with 422, never 500', function (mixed $value) {
    // Given a member
    User::factory()->create(['email' => 'amina@example.com']);

    // When remember has the wrong shape
    $response = spa()->postJson('/api/v1/auth/login', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'remember' => $value,
    ]);

    // Then it is a validation error
    $response->assertUnprocessable()->assertJsonValidationErrors(['remember']);
})->with([[[]], [['a']], [['k' => 'v']], ['maybe']]);

it('clears the per-minute throttle after a successful login', function () {
    // Given four failed attempts for one email
    User::factory()->create(['email' => 'amina@example.com']);
    foreach (range(1, 4) as $i) {
        spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope'])->assertUnprocessable();
    }

    expect(loginMinuteAttempts('amina@example.com'))->toBe(4);

    // When the member then signs in successfully
    spa()->postJson('/api/v1/auth/login', ['email' => 'Amina@Example.com', 'password' => 'correct-horse-battery'])->assertOk();

    // Then the bucket is empty and four more failures are allowed (the minute bucket was cleared)
    foreach (range(1, 4) as $i) {
        spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope'])->assertUnprocessable();
    }
});

it('keeps the per-hour email throttle when a login succeeds', function () {
    // Given a login that succeeded (it counted one hit on the hourly bucket)
    User::factory()->create(['email' => 'amina@example.com']);
    spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery'])->assertOk();

    // Then the hourly bucket was not cleared
    expect(RateLimiter::attempts(md5('login'.'login-hour:amina@example.com')))->toBe(1);
});

it('signs out a token-authenticated request without error', function () {
    // Given a member with an access token (no web session)
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When the token calls logout
    $response = $this->withToken($token)->postJson('/api/v1/auth/logout');

    // Then it succeeds instead of failing on the missing session
    $response->assertNoContent();
});

it('rejects logout for guests', function () {
    // Given nobody is signed in
    // When they call logout
    $response = $this->postJson('/api/v1/auth/logout');

    // Then they are told to authenticate
    $response->assertUnauthorized();
});

it('does not let a correct password through once the minute limit is hit', function () {
    // Given five failed attempts
    User::factory()->create(['email' => 'amina@example.com']);
    foreach (range(1, 5) as $i) {
        spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope'])->assertUnprocessable();
    }

    // When the right password arrives
    $response = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery']);

    // Then it is still throttled, with the standard headers
    $response->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '0');
    $this->assertGuest();
});

it('sets the remember cookie when asked and cycles the remember token on logout', function () {
    // Given a member
    $user = User::factory()->create(['email' => 'amina@example.com', 'remember_token' => 'old-remember-token']);

    // When they log in with remember=true
    $response = spa()->withCredentials()->postJson('/api/v1/auth/login', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'remember' => true,
    ]);

    // Then the long-lived cookie is issued
    $response->assertOk();
    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    expect(collect($names)->contains(fn ($name) => str_starts_with($name, 'remember_web_')))->toBeTrue();

    // And logging out rotates the stored remember token
    $remembered = $user->fresh()->remember_token;
    $this->actingAs($user->fresh());
    spa()->postJson('/api/v1/auth/logout')->assertNoContent();
    expect($user->fresh()->remember_token)->not->toBe($remembered);
});

it('does not set the remember cookie by default', function () {
    // Given a member
    User::factory()->create(['email' => 'amina@example.com']);

    // When they log in without remember
    $response = spa()->withCredentials()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery']);

    // Then there is no remember cookie
    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    expect(collect($names)->contains(fn ($name) => str_starts_with($name, 'remember_web_')))->toBeFalse();
});

it('lets a suspended member end their own token session via logout', function () {
    // Given a suspended member holding a token
    $token = User::factory()->suspended()->create()->createToken('phone')->plainTextToken;

    // When the token calls logout
    $response = $this->withToken($token)->postJson('/api/v1/auth/logout');

    // Then it is allowed (signing out is always possible)
    $response->assertNoContent();
});

it('gives a foreign or malformed password hash the generic 422 on login, never a 500', function (string $kind) {
    // Given a member whose stored hash the configured driver cannot verify
    User::factory()->create(['email' => 'amina@example.com']);
    User::factory()->create(['email' => 'legacy@example.com']);
    $hash = $kind === 'garbage' ? 'garbage' : Hash::driver('bcrypt')->make('correct-horse-battery');
    DB::table('users')->where('email', 'legacy@example.com')->update(['password' => $hash]);
    config(['hashing.driver' => $kind === 'garbage' ? 'bcrypt' : 'argon2id']);
    $wrong = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope']);

    // When they log in
    $response = spa()->postJson('/api/v1/auth/login', ['email' => 'legacy@example.com', 'password' => 'correct-horse-battery']);

    // Then it is the same generic error, recorded as a failed login with the user id
    $response->assertUnprocessable();
    expect($response->json())->toBe($wrong->json());
    $this->assertGuest();
    expect(AuthEvent::query()->where('event', AuthEventType::LoginFailed)->whereNotNull('user_id')->count())->toBe(2);
})->with(['foreign algorithm' => ['foreign'], 'malformed' => ['garbage']]);

it('still answers 422 for a wrong password when the failure log cannot be written', function () {
    // Given bookkeeping that throws (a too-short HMAC key)
    User::factory()->create(['email' => 'amina@example.com']);
    config(['launchpad.hmac_key' => 'short']);

    // When the password is wrong
    $response = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'nope-nope-nope']);

    // Then it is still a 422, not a 500
    $response->assertUnprocessable();
});

it('upgrades an out-of-date password hash after a successful login', function () {
    // Given a member whose hash uses a weaker cost than the configured one
    User::factory()->create(['email' => 'amina@example.com']);
    $old = Hash::driver('bcrypt')->make('correct-horse-battery', ['rounds' => 5]);
    DB::table('users')->where('email', 'amina@example.com')->update(['password' => $old]);

    // When they log in
    spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery'])->assertOk();

    // Then the stored hash is replaced by one that still verifies
    $new = DB::table('users')->where('email', 'amina@example.com')->value('password');
    expect($new)->not->toBe($old);
    expect(Hash::check('correct-horse-battery', $new))->toBeTrue();
    expect(Hash::needsRehash($new))->toBeFalse();
});
