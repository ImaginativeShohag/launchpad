<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'active', 'verified.api'])->get('api/v1/_gated', fn () => ['ok' => true]);
    Route::middleware(['api', 'auth:sanctum', 'recent.login'])->get('api/v1/_recent', fn () => ['ok' => true]);
});

it('blocks unverified members from gated endpoints with a machine-readable code', function () {
    // Given an unverified member
    Sanctum::actingAs(User::factory()->unverified()->create());

    // When they call a gated endpoint
    $response = $this->getJson('/api/v1/_gated');

    // Then they get 403 with the email_unverified code
    $response->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('lets verified members through', function () {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they call a gated endpoint
    // Then it succeeds
    $this->getJson('/api/v1/_gated')->assertOk();
});

it('blocks suspended accounts immediately, even with a valid token', function () {
    // Given a suspended member holding a valid token
    $user = User::factory()->suspended()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When they use it
    $response = $this->withToken($token)->getJson('/api/v1/_gated');

    // Then they are refused
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('does not ask password accounts to have signed in recently', function () {
    // Given a member with a password who signed in long ago
    Sanctum::actingAs(User::factory()->lastLoginMinutesAgo(600)->create());

    // When they hit a recent-login endpoint
    // Then it passes (they prove themselves with the password instead)
    $this->getJson('/api/v1/_recent')->assertOk();
});

it('requires a sign-in within ten minutes for accounts without a password', function (?int $minutesAgo, int $status) {
    // Given a Google-only member who last signed in $minutesAgo minutes ago (or never)
    $factory = User::factory()->withoutPassword();
    $user = ($minutesAgo === null ? $factory : $factory->lastLoginMinutesAgo($minutesAgo))->create();
    Sanctum::actingAs($user);

    // When they hit a recent-login endpoint
    $response = $this->getJson('/api/v1/_recent');

    // Then recent sign-ins pass and old or missing ones are asked to re-authenticate
    $response->assertStatus($status);
    if ($status === 403) {
        $response->assertJsonPath('code', 'reauth_required');
    }
})->with([
    'five minutes ago' => [5, 200],
    'twenty minutes ago' => [20, 403],
    'never' => [null, 403],
]);

it('passes through the verified and active guards without a user, leaving 401 to auth', function () {
    // Given a request with no credentials
    // When it hits a gated endpoint
    // Then the auth guard answers 401 before the guards run
    $this->getJson('/api/v1/_gated')->assertUnauthorized();
    $this->getJson('/api/v1/_recent')->assertUnauthorized();
});

it('lets the guards pass a userless request through instead of throwing', function () {
    // Given the middleware used without auth in front
    Route::middleware(['api', 'active', 'verified.api'])->get('api/v1/_open', fn () => ['ok' => true]);

    // When an anonymous request arrives
    $response = $this->getJson('/api/v1/_open');

    // Then it is answered with JSON 401, not an exception
    $response->assertUnauthorized();
});

it('blocks a deleted account with a valid token', function () {
    // Given a deleted member holding a token
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);

    // When they use it
    $response = $this->withToken($token)->getJson('/api/v1/_gated');

    // Then they are refused
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('pins the recent-login window to ten minutes and fails closed on the future', function (int $secondsAgo, int $status) {
    // Given a Google-only member who signed in $secondsAgo seconds ago (negative = in the future)
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->withoutPassword()->create();
    $user->forceFill(['last_login_at' => now()->subSeconds($secondsAgo)])->saveQuietly();
    Sanctum::actingAs($user->fresh());

    // When they hit a recent-login endpoint
    $response = $this->getJson('/api/v1/_recent');

    // Then exactly ten minutes still passes, one second more or a future stamp does not
    $response->assertStatus($status);
})->with([
    'exactly 10 minutes' => [600, 200],
    '10 minutes 1 second' => [601, 403],
    'in the future' => [-60, 403],
]);
