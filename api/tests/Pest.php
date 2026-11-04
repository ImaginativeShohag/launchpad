<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Google\GoogleIdentity;
use App\Support\Google\GoogleIdentityProvider;
use App\Support\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeGoogleIdentityProvider;
use Tests\TestCase;

require_once __DIR__.'/Support/DashboardFixtures.php';
require_once __DIR__.'/Support/ImageFixtures.php';
require_once __DIR__.'/Support/SearchFixtures.php';
require_once __DIR__.'/Support/ExportFixtures.php';
require_once __DIR__.'/Support/ViewFixtures.php';
require_once __DIR__.'/Support/AntiCheatFixtures.php';

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * A request that looks like it comes from the React site, so Sanctum treats it
 * as stateful (session cookie) instead of token-only.
 */
function spa(): TestCase
{
    return test()->withHeaders([
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173/',
    ]);
}

/** Failed-attempt count in the `login` limiter's per-minute bucket for this ip and email. */
function loginMinuteAttempts(string $email): int
{
    $request = Request::create('/api/v1/auth/login', 'POST', ['email' => $email], server: ['REMOTE_ADDR' => '127.0.0.1']);

    return RateLimiter::attempts(md5('login'.LoginThrottle::minuteKey($request)));
}

/** Replaces the real Google integration with a fixed identity for the current test. */
function fakeGoogleUser(
    string $sub = 'g-1',
    string $email = 'amina@example.com',
    bool $verified = true,
    string $name = 'Amina Rahman',
): void {
    app()->instance(
        GoogleIdentityProvider::class,
        new FakeGoogleIdentityProvider(new GoogleIdentity($sub, $email, $verified, $name)),
    );
}

/** Makes the Google callback fail (bad state, cancelled consent, …). */
function fakeGoogleFailure(): void
{
    app()->instance(GoogleIdentityProvider::class, new FakeGoogleIdentityProvider(null));
}

/** A confirmed member, signed in with a Sanctum token for the rest of the test. */
function signedInMember(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Sanctum::actingAs($user);

    return $user;
}

/** A confirmed staff member (reviewer or admin), signed in for the rest of the test. */
function signedInStaff(Role $role = Role::Reviewer): User
{
    $user = User::factory()->role($role)->create();
    Sanctum::actingAs($user);

    return $user;
}
