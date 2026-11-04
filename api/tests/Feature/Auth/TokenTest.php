<?php

use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

it('issues a hashed, expiring device token to a mobile client', function () {
    // Given a member
    $user = User::factory()->create(['email' => 'amina@example.com']);

    // When the phone app asks for a token
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'Amina’s iPhone',
    ]);

    // Then it receives the plain token once, and only a hash is stored, with a 90-day expiry
    $response->assertCreated()->assertJsonPath('data.user.id', $user->id);
    $plain = $response->json('data.token');
    expect($plain)->toMatch('/^\d+\|lp_/');
    $row = DB::table('personal_access_tokens')->first();
    expect($row->name)->toBe('Amina’s iPhone');
    expect($row->token)->not->toContain($plain);
    expect(now()->diffInDays($row->expires_at, absolute: true))->toBeBetween(89, 91);

    // And the token authenticates later requests, and the events are recorded
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::TokenCreated)->exists())->toBeTrue();
    expect(PersonalAccessToken::findToken($plain)?->tokenable_id)->toBe($user->id);
});

it('refuses a token for wrong credentials with the same error as the web login', function () {
    // Given a member
    User::factory()->create(['email' => 'amina@example.com']);

    // When the password is wrong
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'nope-nope-nope', 'device_name' => 'phone',
    ]);

    // Then it is refused and no token exists
    $response->assertUnprocessable();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('revokes only the token that made the request', function () {
    // Given a member with two devices
    $user = User::factory()->create();
    $phone = $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet');

    // When the phone signs out
    $response = $this->withToken($phone)->deleteJson('/api/v1/auth/token');

    // Then only the phone's token is gone and the revocation is recorded
    $response->assertNoContent();
    expect($user->tokens()->pluck('name')->all())->toBe(['tablet']);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::TokenRevoked)->exists())->toBeTrue();
});

it('rejects array, object, null and empty token fields with 422, never 500', function (string $field, mixed $value) {
    // Given a member and a payload where one field has the wrong shape
    User::factory()->create(['email' => 'amina@example.com']);
    $payload = array_merge(['email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'phone'], [$field => $value]);

    // When it is posted
    $response = $this->postJson('/api/v1/auth/token', $payload);

    // Then validation rejects it and no token exists
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->with(function () {
    foreach (['email', 'password', 'device_name'] as $field) {
        foreach ([[], ['a'], ['k' => 'v'], null, ''] as $i => $value) {
            yield "$field #$i" => [$field, $value];
        }
    }
});

it('issues a token to an unverified member', function () {
    // Given an unverified member
    User::factory()->unverified()->create(['email' => 'amina@example.com']);

    // When the phone app asks for a token
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'phone',
    ]);

    // Then they get one (browse-only until verified)
    $response->assertCreated();
});

it('refuses a token to suspended, deleted and password-less members with the same error', function () {
    // Given three unusable accounts and one wrong password
    User::factory()->create(['email' => 'amina@example.com']);
    User::factory()->suspended()->create(['email' => 'banned@example.com']);
    User::factory()->create(['email' => 'gone@example.com', 'deleted_at' => now()]);
    User::factory()->withoutPassword()->create(['email' => 'google@example.com']);
    $attempt = fn (string $email, string $password) => $this->postJson('/api/v1/auth/token', [
        'email' => $email, 'password' => $password, 'device_name' => 'phone',
    ]);

    // When each asks for a token
    $wrong = $attempt('amina@example.com', 'nope-nope-nope');
    $others = [
        $attempt('banned@example.com', 'correct-horse-battery'),
        $attempt('gone@example.com', 'correct-horse-battery'),
        $attempt('google@example.com', 'whatever-password'),
    ];

    // Then all are the same 422 and each failure is recorded with the user id when known
    foreach ($others as $response) {
        $response->assertUnprocessable();
        expect($response->json())->toBe($wrong->json());
    }
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    expect(AuthEvent::query()->where('event', AuthEventType::LoginFailed)->count())->toBe(4);
    expect(AuthEvent::query()->where('event', AuthEventType::LoginFailed)->whereNotNull('user_id')->count())->toBe(4);
});

it('clears the per-minute throttle after a successful token request', function () {
    // Given four failed attempts
    User::factory()->create(['email' => 'amina@example.com']);
    $post = fn (string $password) => $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => $password, 'device_name' => 'phone',
    ]);
    foreach (range(1, 4) as $i) {
        $post('nope-nope-nope')->assertUnprocessable();
    }

    expect(loginMinuteAttempts('amina@example.com'))->toBe(4);

    // When a token is issued
    $post('correct-horse-battery')->assertCreated();

    // Then the bucket is empty and four more failures are allowed
    expect(loginMinuteAttempts('amina@example.com'))->toBe(0);
    foreach (range(1, 4) as $i) {
        $post('nope-nope-nope')->assertUnprocessable();
    }
});

it('refuses to revoke when the request has no access token', function () {
    // Given a member signed in through the web session
    $user = User::factory()->create();
    $this->actingAs($user);

    // When they call DELETE /auth/token
    $response = spa()->deleteJson('/api/v1/auth/token');

    // Then it is a 400 and nothing is deleted
    $response->assertStatus(400);
});

it('rejects revoke for guests', function () {
    // Given nobody is signed in
    // When they call DELETE /auth/token
    $response = $this->deleteJson('/api/v1/auth/token');

    // Then they are told to authenticate
    $response->assertUnauthorized();
});

it('gives a foreign or malformed password hash the generic 422 on token issue, never a 500', function (string $kind) {
    // Given a member whose stored hash the configured driver cannot verify
    User::factory()->create(['email' => 'amina@example.com']);
    User::factory()->create(['email' => 'legacy@example.com']);
    $hash = $kind === 'garbage' ? 'garbage' : Hash::driver('bcrypt')->make('correct-horse-battery');
    DB::table('users')->where('email', 'legacy@example.com')->update(['password' => $hash]);
    config(['hashing.driver' => $kind === 'garbage' ? 'bcrypt' : 'argon2id']);
    $post = fn (string $email, string $password) => $this->postJson('/api/v1/auth/token', [
        'email' => $email, 'password' => $password, 'device_name' => 'phone',
    ]);
    $wrong = $post('amina@example.com', 'nope-nope-nope');

    // When the legacy member asks for a token
    $response = $post('legacy@example.com', 'correct-horse-battery');

    // Then it is the same generic error and no token exists
    $response->assertUnprocessable();
    expect($response->json())->toBe($wrong->json());
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->with(['foreign algorithm' => ['foreign'], 'malformed' => ['garbage']]);

it('still issues exactly one token when the success log cannot be written', function () {
    // Given bookkeeping that throws (a too-short HMAC key)
    User::factory()->create(['email' => 'amina@example.com']);
    config(['launchpad.hmac_key' => 'short']);

    // When a token is requested
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'phone',
    ]);

    // Then the client gets its token and exactly one row exists
    $response->assertCreated();
    expect(DB::table('personal_access_tokens')->count())->toBe(1);
});

it('leaves no token behind when a required write fails', function () {
    // Given a database that refuses to update users
    User::factory()->create(['email' => 'amina@example.com']);
    DB::unprepared("CREATE FUNCTION test_block_user_update() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'blocked'; END; \$\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER test_block_user_update BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION test_block_user_update()');

    // When a token is requested
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'phone',
    ]);

    // Then the request fails and no live token was left behind
    $response->assertStatus(500);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('rejects an over-long or blank device name', function (string $name) {
    // Given a member
    User::factory()->create(['email' => 'amina@example.com']);

    // When the device name is too long or blank after cleaning
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => $name,
    ]);

    // Then it is a validation error and no token exists
    $response->assertUnprocessable()->assertJsonValidationErrors(['device_name']);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->with(['101 chars' => [str_repeat('a', 101)], 'spaces' => ['   '], 'control chars only' => ["\x07\t\n"]]);

it('strips control characters from the stored device name', function () {
    // Given a member
    User::factory()->create(['email' => 'amina@example.com']);

    // When the device name contains control characters
    $this->postJson('/api/v1/auth/token', [
        'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => " ph\x07on\te\n ",
    ])->assertCreated();

    // Then they are removed and the name is trimmed
    expect(DB::table('personal_access_tokens')->value('name'))->toBe('phone');
});

it('lets a suspended member revoke their own token', function () {
    // Given a suspended member holding a token
    $user = User::factory()->suspended()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When the token is revoked
    $response = $this->withToken($token)->deleteJson('/api/v1/auth/token');

    // Then it works (signing out is always possible)
    $response->assertNoContent();
    expect($user->tokens()->count())->toBe(0);
});
