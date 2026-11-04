<?php

use App\Actions\Admin\RestoreUser;
use App\Actions\Admin\SuspendUser;
use App\Enums\Role;
use App\Exceptions\CommunityRuleViolation;
use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));
afterEach(fn () => AuditLog::flushEventListeners());

function suspendUserAs(string $handle, mixed $payload = ['reason' => 'Harassing other members'])
{
    return test()->postJson("/api/v1/admin/users/{$handle}/suspend", $payload);
}

function restoreUserAs(string $handle, mixed $payload = [])
{
    return test()->postJson("/api/v1/admin/users/{$handle}/restore", $payload);
}

/** A signed-in admin and a target of the given role with a session row and a device token. */
function suspensionSetup(Role $role = Role::Member): array
{
    $admin = signedInStaff(Role::Admin);
    $target = User::factory()->role($role)->create(['handle' => 'target_x', 'remember_token' => 'old-remember-token']);
    DB::table('sessions')->insert(['id' => 'sess-target-1', 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    $token = $target->createToken('phone')->plainTextToken;

    return [$admin, $target, $token];
}

it('suspends a member or a reviewer: stored, one audit row, signed out everywhere, remember token rotated', function (Role $role) {
    // Given an admin, a target with a session, a device token and a remember token
    [$admin, $target, $token] = suspensionSetup($role);

    // When the admin suspends them with a reason
    $response = suspendUserAs('target_x', ['reason' => '  Harassing other members  ']);

    // Then 200 with the state, the columns are stored, and exactly one audit row holds the reason only
    $response->assertOk()->assertJsonPath('data.is_suspended', true)->assertJsonPath('data.suspended_reason', 'Harassing other members')->assertJsonPath('data.handle', 'target_x');
    expect(array_keys($response->json('data')))->toBe(['id', 'handle', 'is_suspended', 'suspended_at', 'suspended_reason']);
    $fresh = $target->fresh();
    expect($fresh->suspended_at)->not->toBeNull()->and($fresh->suspended_reason)->toBe('Harassing other members');
    $log = AuditLog::query()->where('action', 'user.suspended')->sole();
    expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($target->id)->and($log->meta)->toEqual(['reason' => 'Harassing other members']);
    expect(AuditLog::query()->count())->toBe(1);
    // And the remember token was rotated, the session and every token are gone
    expect($fresh->remember_token)->not->toBe('old-remember-token')->not->toBeNull();
    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)->and(DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count())->toBe(0);
    // And the old token stops working on the very next request
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
})->with([Role::Member, Role::Reviewer]);

it('keeps a suspended member out of password login, device tokens and Google sign-in', function () {
    // Given a suspended member
    [, $target] = suspensionSetup();
    $target->forceFill(['email' => 'target@example.com'])->save();
    suspendUserAs('target_x')->assertOk();
    $this->app['auth']->forgetGuards();

    // When they try the three doors
    $login = spa()->postJson('/api/v1/auth/login', ['email' => 'target@example.com', 'password' => 'correct-horse-battery']);
    $token = $this->postJson('/api/v1/auth/token', ['email' => 'target@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'phone']);
    SocialAccount::factory()->for($target)->create(['provider_user_id' => 'g-1']);
    fakeGoogleUser(sub: 'g-1', email: 'target@example.com');
    $google = $this->get('/api/v1/auth/google/callback');

    // Then none lets them in
    $login->assertUnprocessable();
    $token->assertUnprocessable();
    $this->assertGuest();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count())->toBe(0);
    $google->assertRedirect('http://localhost:5173/signin?error=account_unavailable');
});

it('refuses with nothing changed: oneself, another admin, unknown and deleted handles', function (string $case) {
    // Given an admin, another admin, a deleted member and a member
    $admin = signedInStaff(Role::Admin);
    User::factory()->role(Role::Admin)->create(['handle' => 'other_admin']);
    User::factory()->create(['handle' => 'gone_member', 'deleted_at' => now()]);
    $handle = match ($case) {
        'self' => $admin->handle,
        'admin' => 'other_admin',
        'deleted' => 'gone_member',
        'unknown' => 'nobody',
        'odd' => str_repeat('a', 200),
        'nul' => "x\0y",
    };

    // When the admin tries to suspend that handle
    $response = suspendUserAs($handle);

    // Then self and admins are 403 cannot_suspend, everything else the uniform 404, and nothing was written
    in_array($case, ['self', 'admin'], true) ? $response->assertForbidden()->assertJsonPath('code', 'cannot_suspend') : $response->assertNotFound();
    expect(User::query()->whereNotNull('suspended_at')->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with(['self', 'admin', 'deleted', 'unknown', 'odd', 'nul']);

it('answers a non-admin 403 for an existing handle, an unknown one and an invalid body alike, and the guards before that', function () {
    // Given a member target and a reviewer, a member and a guest as callers
    $target = User::factory()->create(['handle' => 'target_x']);

    // When non-admins call suspend and restore with an existing handle, an unknown handle, and a nonsense body
    $messages = [];
    foreach ([Role::Reviewer, Role::Member] as $role) {
        Sanctum::actingAs(User::factory()->role($role)->create());
        foreach (['target_x', 'nobody', str_repeat('z', 100)] as $handle) {
            foreach ([['reason' => 'x'], ['reason' => ['a']], []] as $body) {
                $messages[] = suspendUserAs($handle, $body)->assertForbidden()->json('message');
                $messages[] = restoreUserAs($handle, $body)->assertForbidden()->json('message');
            }
        }
    }

    // Then every answer is the same 403, and nothing changed
    expect(array_unique($messages))->toHaveCount(1);
    expect($target->fresh()->suspended_at)->toBeNull()->and(AuditLog::query()->count())->toBe(0);
    // And the guards come first: guest 401, suspended admin and unverified admin 403 with their codes
    $this->app['auth']->forgetGuards();
    suspendUserAs('target_x')->assertUnauthorized();
    restoreUserAs('target_x')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    suspendUserAs('target_x')->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    suspendUserAs('nobody')->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('refuses a stale actor who lost the admin role between the middleware and the action', function () {
    // Given an actor model that still says admin, but the row says member
    $target = User::factory()->create();
    $actor = User::factory()->role(Role::Admin)->create();
    User::factory()->role(Role::Admin)->create();
    $actor->forceFill(['role' => Role::Member])->saveQuietly();
    $stale = (clone $actor)->forceFill(['role' => Role::Admin]);

    // When the actions run with the stale model
    // Then both refuse and nothing changed
    expect(fn () => app(SuspendUser::class)->handle($stale, $target, 'x'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RestoreUser::class)->handle($stale, $target))->toThrow(AuthorizationException::class);
    expect($target->fresh()->suspended_at)->toBeNull()->and(AuditLog::query()->count())->toBe(0);
});

it('validates the reason: required, a string, 500 characters at most, valid text', function (mixed $reason) {
    // Given an admin and a member
    suspensionSetup();

    // When the reason is bad
    $response = suspendUserAs('target_x', ['reason' => $reason]);

    // Then 422 (never 500) and the member is untouched
    $response->assertUnprocessable();
    expect(User::query()->whereNotNull('suspended_at')->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with([[null], [''], ['   '], ["\u{202E}\u{200B}"], [['a']], [['a' => 'b']], [5], [true], [str_repeat('a', 501)], ["bad \xB1\x31"]]);

it('rejects a missing reason and accepts exactly 500 characters, stripping control characters and ignoring extra fields', function () {
    // Given an admin and two members
    suspensionSetup();
    User::factory()->create(['handle' => 'second_x']);

    // When no reason is sent, then 500 multibyte characters with control characters and an extra field
    suspendUserAs('target_x', [])->assertUnprocessable();
    $ok = suspendUserAs('second_x', ['reason' => str_repeat('é', 500)."\x00\x07", 'role' => 'admin', 'extra' => ['x']]);

    // Then the first is 422 and the second is stored as 500 characters, with no role change
    $ok->assertOk();
    expect(mb_strlen((string) User::query()->where('handle', 'second_x')->value('suspended_reason')))->toBe(500);
    expect(DB::table('users')->where('handle', 'second_x')->value('role'))->toBe('member');
});

it('is idempotent: a second suspend keeps the first reason and time, writes no audit row and signs nobody out again', function () {
    // Given an already suspended member who then gets a new session
    [, $target] = suspensionSetup();
    suspendUserAs('target_x', ['reason' => 'First reason'])->assertOk();
    $first = $target->fresh();
    $this->travel(5)->minutes();
    DB::table('sessions')->insert(['id' => 'sess-target-2', 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

    // When they are suspended again with another reason
    $response = suspendUserAs('target_x', ['reason' => 'Second reason']);

    // Then 200 with the original reason and time, still one audit row, the new session and the remember token untouched
    $response->assertOk()->assertJsonPath('data.suspended_reason', 'First reason');
    $again = $target->fresh();
    expect($again->suspended_at->eq($first->suspended_at))->toBeTrue()->and($again->remember_token)->toBe($first->remember_token);
    expect(AuditLog::query()->where('action', 'user.suspended')->count())->toBe(1)->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});

it('restores a member: both columns cleared, one audit row with the previous reason, a fresh login works, old sessions stay dead', function () {
    // Given a suspended member with a password login
    [$admin, $target, $oldToken] = suspensionSetup();
    $target->forceFill(['email' => 'target@example.com'])->save();
    suspendUserAs('target_x', ['reason' => 'Harassing other members'])->assertOk();

    // When the admin restores them
    $response = restoreUserAs('target_x');

    // Then 200 not suspended, columns cleared, one user.restored row with the previous reason, and the earlier suspend row remains
    $response->assertOk()->assertJsonPath('data.is_suspended', false)->assertJsonPath('data.suspended_at', null)->assertJsonPath('data.suspended_reason', null);
    $fresh = $target->fresh();
    expect($fresh->suspended_at)->toBeNull()->and($fresh->suspended_reason)->toBeNull();
    $log = AuditLog::query()->where('action', 'user.restored')->sole();
    expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($target->id)->and($log->meta)->toEqual(['previous_reason' => 'Harassing other members']);
    expect(AuditLog::query()->count())->toBe(2);
    // And the old token is still dead, a fresh login works, and the member can act again
    $this->app['auth']->forgetGuards();
    $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $login = $this->postJson('/api/v1/auth/token', ['email' => 'target@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'phone'])->assertCreated();
    $this->app['auth']->forgetGuards();
    $this->withToken($login->json('data.token'))->getJson('/api/v1/me')->assertOk();
});

it('allows a second suspension with a new reason after a restore', function () {
    // Given a member suspended and restored
    suspensionSetup();
    suspendUserAs('target_x', ['reason' => 'First'])->assertOk();
    restoreUserAs('target_x')->assertOk();

    // When they are suspended again
    suspendUserAs('target_x', ['reason' => 'Second'])->assertOk()->assertJsonPath('data.suspended_reason', 'Second');

    // Then the new reason is stored and the audit trail has all three rows
    expect(AuditLog::query()->orderBy('created_at')->orderBy('id')->pluck('action')->all())->toBe(['user.suspended', 'user.restored', 'user.suspended']);
});

it('restores a member who is not suspended without writing anything, and answers 404 for a deleted or unknown handle', function () {
    // Given an admin and an ordinary member
    suspensionSetup();
    User::factory()->create(['handle' => 'gone_member', 'deleted_at' => now()]);

    // When restore is called for them, for a deleted account, and for an unknown handle (with a stray body)
    $ok = restoreUserAs('target_x', ['reason' => ['x']]);

    // Then the first is 200 and unchanged with no audit row, the others 404
    $ok->assertOk()->assertJsonPath('data.is_suspended', false);
    expect(AuditLog::query()->count())->toBe(0);
    restoreUserAs('gone_member')->assertNotFound();
    restoreUserAs('nobody')->assertNotFound();
});

it('rolls the suspension back when the audit write fails: still active, sessions and tokens intact', function () {
    // Given a member with a session and a token, and an audit insert that fails
    [$admin, $target] = suspensionSetup();
    AuditLog::creating(function () {
        throw new RuntimeException('audit down');
    });

    // When the admin tries to suspend them
    $run = fn () => app(SuspendUser::class)->handle($admin, $target, 'Reason');

    // Then it throws and nothing changed, not even the remember token
    expect($run)->toThrow(RuntimeException::class);
    $fresh = $target->fresh();
    expect($fresh->suspended_at)->toBeNull()->and($fresh->remember_token)->toBe('old-remember-token');
    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1)->and(DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count())->toBe(1);
});

it('takes the active-admin set first and the target row second, and no product row at all', function () {
    // Given two admins and a member
    $admin = User::factory()->role(Role::Admin)->create();
    User::factory()->role(Role::Admin)->create();
    $target = User::factory()->create();
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'from "products"')) {
            $log[] = $sql;
        }
    });

    // When the admin suspends the member
    app(SuspendUser::class)->handle($admin, $target, 'Reason');

    // Then the first lock is the ordered admin set, the second the target row, and no query touches products
    expect($log)->toHaveCount(2);
    expect($log[0])->toContain('from "users"')->toContain('"role" = ')->toContain('order by "id" asc')->toContain('for update');
    expect($log[1])->toContain('from "users"')->toContain('"users"."id" = ')->toContain('for update');
    expect(collect($log)->filter(fn (string $sql) => str_contains($sql, 'from "products"'))->all())->toBe([]);
});

it('reports the cannot_suspend code through the exception itself', function () {
    // Given the rule violation
    $violation = CommunityRuleViolation::cannotSuspend();

    // When it is rendered
    // Then it is a 403 with the stable code
    expect($violation->render()->getStatusCode())->toBe(403)->and($violation->errorCode)->toBe('cannot_suspend');
    expect($violation)->toBeInstanceOf(ShouldntReport::class);
    expect(fn () => app(SuspendUser::class)->handle(User::factory()->role(Role::Admin)->create(), User::factory()->create(['deleted_at' => now()]), 'x'))->toThrow(ModelNotFoundException::class);
});

it('answers 403 to a non-admin, 422 to an admin with an invalid body even for an unknown handle (validation before the lookup), and 404 only once the body is valid', function () {
    // Given an invalid body
    $bad = ['reason' => ['x']];

    // When a member, then an admin, send it for an unknown handle, and the admin sends a valid one
    Sanctum::actingAs(User::factory()->create());
    $member = suspendUserAs('nobody', $bad);
    signedInStaff(Role::Admin);
    $invalid = suspendUserAs('nobody', $bad);
    $valid = suspendUserAs('nobody');

    // Then 403, 422, 404
    $member->assertForbidden();
    $invalid->assertUnprocessable();
    $valid->assertNotFound();
});
