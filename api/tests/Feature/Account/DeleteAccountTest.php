<?php

use App\Actions\Auth\DeleteAccount;
use App\Enums\AuthEventType;
use App\Enums\Role;
use App\Exceptions\LastAdminException;
use App\Models\AuthEvent;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('anonymises the account in place and removes every way to sign in', function () {
    // Given a member with a photo, a link, a Google link, a session, a reset token and a device token
    Storage::fake('public');
    Storage::disk('public')->put('avatars/a.webp', 'img');
    $user = User::factory()->create([
        'name' => 'Amina Rahman', 'handle' => 'amina_r', 'email' => 'amina@example.com',
        'headline' => 'Maker', 'avatar_path' => 'avatars/a.webp', 'risk_score' => 7,
        'pending_email' => 'next@example.com', 'pending_email_expires_at' => now()->addHour(),
        'last_login_at' => now(),
    ]);
    $user->links()->create(['kind' => 'website', 'url' => 'https://amina.dev', 'position' => 0]);
    SocialAccount::factory()->for($user)->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => 'amina@example.com', 'token' => 'x', 'created_at' => now()]);
    DB::table('password_reset_tokens')->insert(['email' => 'next@example.com', 'token' => 'z', 'created_at' => now()]);
    DB::table('password_reset_tokens')->insert(['email' => 'other@example.com', 'token' => 'y', 'created_at' => now()]);
    $user->createToken('phone');
    Sanctum::actingAs($user);

    // When they delete their account with their password
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then the row stays but holds no personal data
    $response->assertNoContent();
    $fresh = User::query()->findOrFail($user->id);
    $lower = Str::lower($user->id);
    expect($fresh->name)->toBe('Deleted member');
    expect($fresh->handle)->toBe('deleted-'.substr($lower, -20))->and(strlen($fresh->handle))->toBe(28);
    expect($fresh->email)->toBe('deleted+'.$lower.'@invalid');
    expect($fresh->email_verified_at)->toBeNull();
    expect($fresh->password)->toBeNull();
    expect($fresh->headline)->toBeNull()->and($fresh->avatar_path)->toBeNull();
    expect($fresh->pending_email)->toBeNull()->and($fresh->pending_email_expires_at)->toBeNull();
    expect($fresh->remember_token)->toBeNull();
    expect($fresh->last_login_at)->toBeNull();
    expect($fresh->role)->toBe(Role::Member);
    expect($fresh->deleted_at)->not->toBeNull();
    expect($fresh->risk_score)->toBe(7);

    // And links, Google link, sessions, tokens, reset token and the photo are gone; the deletion is recorded
    expect($user->links()->count())->toBe(0);
    expect($user->socialAccounts()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['other@example.com']);
    Storage::disk('public')->assertMissing('avatars/a.webp');
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::AccountDeleted)->exists())->toBeTrue();

    // And the old email can no longer sign in, and the old profile URL is a 404
    $this->postJson('/api/v1/auth/token', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'x'])
        ->assertUnprocessable();
    $this->getJson('/api/v1/users/amina_r')->assertNotFound();
});

it('takes the staff role away from a deleted reviewer', function () {
    // Given a reviewer
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    Sanctum::actingAs($reviewer);

    // When they delete their account
    $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery'])->assertNoContent();

    // Then the row keeps no staff role
    expect($reviewer->fresh()->role)->toBe(Role::Member);
});

it('lets the old token and the old email start over after deletion', function () {
    // Given a member with a device token
    $user = User::factory()->create(['email' => 'amina@example.com', 'handle' => 'amina_r']);
    $plain = $user->createToken('phone')->plainTextToken;

    // When they delete their account with that token
    $this->withToken($plain)->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery'])->assertNoContent();
    app('auth')->forgetGuards();

    // Then the token is dead
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

    // And the same email and handle can register again as a new member
    $this->flushHeaders();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Amina Again', 'email' => 'amina@example.com', 'password' => 'correct-horse-battery', 'handle' => 'amina_r',
    ])->assertCreated();
    expect(User::query()->where('email', 'amina@example.com')->value('id'))->not->toBe($user->id);
});

it('frees the Google identity so it can be linked again', function () {
    // Given a member with a Google link
    $user = User::factory()->create(['email' => 'amina@example.com']);
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'g-1']);
    $plain = $user->createToken('phone')->plainTextToken;

    // When they delete their account and then sign in with the same Google identity
    $this->withToken($plain)->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery'])->assertNoContent();
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web'); // auth:sanctum switched the default guard for this process
    $this->flushHeaders();
    fakeGoogleUser(sub: 'g-1', email: 'amina@example.com');
    $this->get('/api/v1/auth/google/callback')->assertRedirect();

    // Then a new account owns that Google identity
    $owner = SocialAccount::query()->where('provider_user_id', 'g-1')->firstOrFail();
    expect($owner->user_id)->not->toBe($user->id);
});

it('ends the web session too', function () {
    // Given a member signed in from the web app
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    // When they delete their account
    spa()->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery'])->assertNoContent();

    // Then the web guard no longer knows them and the remember token was not re-created
    $this->assertGuest('web');
    expect(DB::table('users')->where('id', $user->id)->value('remember_token'))->toBeNull();
});

it('refuses a wrong or missing password and changes nothing', function () {
    // Given a member with a password
    $user = User::factory()->create(['name' => 'Amina Rahman']);
    Sanctum::actingAs($user);

    // When the password is wrong, and when it is missing
    $wrong = $this->deleteJson('/api/v1/me', ['current_password' => 'wrong-password']);
    $missing = $this->deleteJson('/api/v1/me');

    // Then both are refused and the account is intact
    $wrong->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $missing->assertUnprocessable()->assertJsonValidationErrors('current_password');
    expect($user->fresh()->name)->toBe('Amina Rahman')->and($user->fresh()->deleted_at)->toBeNull();
});

it('answers a malformed password with 422, never 500', function (mixed $payload) {
    // Given a member with a password
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When current_password is an array, object, null, number or boolean
    $response = $this->deleteJson('/api/v1/me', ['current_password' => $payload]);

    // Then it is a validation error and nothing is deleted
    $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
    expect($user->fresh()->deleted_at)->toBeNull();
})->with([
    'array' => [['a']],
    'empty array' => [[]],
    'object' => [['a' => 'b']],
    'null' => [null],
    'integer' => [123],
    'boolean' => [true],
]);

it('requires a recent sign-in from a Google-only member instead of a password', function () {
    // Given a Google-only member who last signed in 20 minutes ago
    $user = User::factory()->withoutPassword()->lastLoginMinutesAgo(20)->create();
    Sanctum::actingAs($user);

    // When they try to delete
    $stale = $this->deleteJson('/api/v1/me');

    // Then they must sign in again
    $stale->assertForbidden()->assertJsonPath('code', 'reauth_required');
    expect($user->fresh()->deleted_at)->toBeNull();

    // And after a fresh sign-in the deletion goes through
    $user->forceFill(['last_login_at' => now()->subMinutes(5)])->save();
    $this->deleteJson('/api/v1/me')->assertNoContent();
    expect($user->fresh()->deleted_at)->not->toBeNull();
});

it('requires sign-in', function () {
    // Given no one is signed in
    // When /me is deleted
    // Then it is a 401
    $this->deleteJson('/api/v1/me')->assertUnauthorized();
});

it('never lets a suspended member reach the endpoint', function () {
    // Given a suspended member (the active middleware blocks them, so suspension data is never rewritten)
    $user = User::factory()->suspended()->create();
    Sanctum::actingAs($user);

    // When they try to delete
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then it is refused and the reason is untouched
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect($user->fresh()->deleted_at)->toBeNull()->and($user->fresh()->suspended_reason)->toBe('test');
});

it('only lets members delete their own account', function () {
    // Given two members
    $a = User::factory()->create();
    $b = User::factory()->create();

    // When the policy is asked
    // Then a member may delete themselves but not someone else
    expect(Gate::forUser($a)->allows('deleteAccount', $a))->toBeTrue();
    expect(Gate::forUser($a)->allows('deleteAccount', $b))->toBeFalse();
});

it('refuses to delete the last admin', function () {
    // Given exactly one admin (a deleted admin and a reviewer do not count)
    $admin = User::factory()->role(Role::Admin)->create();
    User::factory()->role(Role::Admin)->create(['deleted_at' => now()]);
    User::factory()->role(Role::Reviewer)->create();
    Sanctum::actingAs($admin);

    // When they delete their account
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then it is refused with a clear code and nothing changes
    $response->assertForbidden()->assertJsonPath('code', 'last_admin');
    expect($admin->fresh()->deleted_at)->toBeNull()->and($admin->fresh()->role)->toBe(Role::Admin);
    expect(Gate::forUser($admin)->allows('deleteAccount', $admin))->toBeFalse();
});

it('lets an admin delete themself while another admin remains', function () {
    // Given two admins
    $first = User::factory()->role(Role::Admin)->create();
    $second = User::factory()->role(Role::Admin)->create();
    Sanctum::actingAs($first);

    // When one deletes their account
    $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery'])->assertNoContent();

    // Then they are anonymised and demoted, and the other is now the last admin
    expect($first->fresh()->deleted_at)->not->toBeNull()->and($first->fresh()->role)->toBe(Role::Member);
    expect(Gate::forUser($second->fresh())->allows('deleteAccount', $second->fresh()))->toBeFalse();
});

it('re-checks the last admin rule inside the action', function () {
    // Given a single admin whose policy check was somehow skipped
    $admin = User::factory()->role(Role::Admin)->create();

    // When the action runs directly
    // Then it refuses and rolls back
    expect(fn () => app(DeleteAccount::class)->handle($admin))->toThrow(LastAdminException::class);
    expect($admin->fresh()->deleted_at)->toBeNull();
});

it('gates role changes: admins move members and reviewers, never themselves or another admin', function () {
    // Given an admin, another admin, a reviewer and a member
    $admin = User::factory()->role(Role::Admin)->create();
    $other = User::factory()->role(Role::Admin)->create();
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $member = User::factory()->create();

    // When the policy is asked
    // Then only member and reviewer targets are allowed, and only for an admin
    expect(Gate::forUser($admin)->allows('changeRole', $member))->toBeTrue();
    expect(Gate::forUser($admin)->allows('changeRole', $reviewer))->toBeTrue();
    expect(Gate::forUser($admin)->allows('changeRole', $admin))->toBeFalse();
    expect(Gate::forUser($admin)->allows('changeRole', $other))->toBeFalse();
    expect(Gate::forUser($reviewer)->allows('changeRole', $member))->toBeFalse();
});

it('locks the admin set before any single row for an admin caller, so concurrent admin deletions cannot deadlock', function () {
    // Given two admins and a recorder of every query
    $admin = User::factory()->role(Role::Admin)->create();
    User::factory()->role(Role::Admin)->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    // When one admin is deleted
    app(DeleteAccount::class)->handle($admin);

    // Then the products lock comes first, then the role-filtered admin set, then the own-row lock
    expect($locks[0])->toContain('"products"')->and($locks[1])->toContain('"role"')->and($locks[2])->not->toContain('"role"');
});

it('is idempotent when the account was already deleted by a concurrent request', function () {
    // Given a stale in-memory copy of a member who has meanwhile been deleted
    $stale = User::factory()->create();
    $staleForAction = User::query()->findOrFail($stale->id);
    app(DeleteAccount::class)->handle(User::query()->findOrFail($stale->id));

    // When the stale copy is deleted again, directly and over HTTP
    $direct = app(DeleteAccount::class)->handle($staleForAction);
    Sanctum::actingAs($stale);
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then nothing changes, the response is still 204, and only one deletion is recorded
    expect($direct)->toBeFalse();
    $response->assertNoContent();
    expect(AuthEvent::query()->where('user_id', $stale->id)->where('event', AuthEventType::AccountDeleted)->count())->toBe(1);
});

it('still answers 204 and keeps the anonymised row when the avatar file cannot be deleted', function () {
    // Given a member with a photo and a disk that fails on delete
    Exceptions::fake();
    $user = User::factory()->create(['avatar_path' => 'avatars/a.webp']);
    Storage::shouldReceive('disk->delete')->once()->andThrow(new RuntimeException('disk down'));
    Sanctum::actingAs($user);

    // When they delete their account
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then it succeeded, the row is anonymised and the failure was reported
    $response->assertNoContent();
    expect($user->fresh()->deleted_at)->not->toBeNull()->and($user->fresh()->avatar_path)->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('still answers 204 when the audit write fails', function () {
    // Given an audit insert that fails
    Exceptions::fake();
    $user = User::factory()->create();
    AuthEvent::creating(fn () => DB::statement('select 1/0'));
    Sanctum::actingAs($user);

    // When they delete their account
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then the account is deleted anyway and the failure was reported
    $response->assertNoContent();
    expect($user->fresh()->deleted_at)->not->toBeNull();
    Exceptions::assertReported(QueryException::class);
});

it('throttles the sixth wrong password with 429', function () {
    // Given a member who has already guessed wrong five times
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    foreach (range(1, 5) as $_) {
        $this->deleteJson('/api/v1/me', ['current_password' => 'wrong-password'])->assertUnprocessable();
    }

    // When a sixth attempt arrives, even with the right password
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then it is throttled and nothing is deleted
    $response->assertStatus(429);
    expect($user->fresh()->deleted_at)->toBeNull();
});
