<?php

use App\Actions\Admin\ChangeUserRole;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\Sanctum;

it('lets an admin promote a member to reviewer and audits it', function () {
    // Given an admin and a member
    $admin = User::factory()->role(Role::Admin)->create();
    $member = User::factory()->create(['handle' => 'rafi_k']);
    Sanctum::actingAs($admin);

    // When the admin promotes the member
    $response = $this->patchJson('/api/v1/admin/users/rafi_k/role', ['role' => 'reviewer']);

    // Then the role changes and an audit row records who, what, before and after
    $response->assertOk()->assertJsonPath('data.role', 'reviewer');
    expect($member->fresh()->role)->toBe(Role::Reviewer);
    $log = AuditLog::query()->where('action', 'user.role_changed')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id);
    expect($log->subject_id)->toBe($member->id);
    expect($log->meta)->toMatchArray(['before' => 'member', 'after' => 'reviewer']);
});

it('lets an admin demote a reviewer back to member', function () {
    // Given an admin and a reviewer
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    $reviewer = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one']);

    // When the admin demotes the reviewer
    $this->patchJson('/api/v1/admin/users/rev_one/role', ['role' => 'member'])->assertOk();

    // Then the reviewer is a member again
    expect($reviewer->fresh()->role)->toBe(Role::Member);
});

it('does nothing and writes no audit row when the role is already the requested one', function () {
    // Given an admin and a reviewer
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one']);

    // When the same role is requested again
    $response = $this->patchJson('/api/v1/admin/users/rev_one/role', ['role' => 'reviewer']);

    // Then it succeeds without a second audit entry
    $response->assertOk()->assertJsonPath('data.role', 'reviewer');
    expect(AuditLog::query()->count())->toBe(0);
});

it('refuses role changes that are not allowed', function (Role $actorRole, string $targetHandle, string $role, int $status) {
    // Given an actor with a role, themselves plus an admin and a member as possible targets
    $actor = User::factory()->role($actorRole)->create(['handle' => 'actor_x']);
    User::factory()->role(Role::Admin)->create(['handle' => 'other_admin']);
    User::factory()->create(['handle' => 'plain_member']);
    Sanctum::actingAs($actor);

    // When they try to change a role
    $response = $this->patchJson("/api/v1/admin/users/{$targetHandle}/role", ['role' => $role]);

    // Then it is refused with the expected status
    $response->assertStatus($status);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'reviewer cannot' => [Role::Reviewer, 'plain_member', 'reviewer', 403],
    'member cannot' => [Role::Member, 'plain_member', 'reviewer', 403],
    'admin cannot change self' => [Role::Admin, 'actor_x', 'member', 403],
    'admin cannot change another admin' => [Role::Admin, 'other_admin', 'member', 403],
    'admin cannot grant admin through the API' => [Role::Admin, 'plain_member', 'admin', 422],
    'unknown role' => [Role::Admin, 'plain_member', 'wizard', 422],
    'unknown member' => [Role::Admin, 'nobody', 'reviewer', 404],
]);

it('rejects array, object, null and empty role payloads with 422', function (array $payload) {
    // Given an admin and a member
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    $member = User::factory()->create(['handle' => 'rafi_k']);

    // When the role is not a plain string
    $response = $this->patchJson('/api/v1/admin/users/rafi_k/role', $payload);

    // Then it is a validation error, never a 500, and nothing changes
    $response->assertUnprocessable()->assertJsonValidationErrors('role');
    expect($member->fresh()->role)->toBe(Role::Member);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'array' => [['role' => ['reviewer']]],
    'object' => [['role' => ['a' => 'reviewer']]],
    'null' => [['role' => null]],
    'empty string' => [['role' => '']],
    'missing' => [[]],
    'integer' => [['role' => 1]],
    'bool' => [['role' => true]],
]);

it('does not resolve a deleted or anonymised account', function () {
    // Given an admin and a deleted member
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    User::factory()->create(['handle' => 'gone_one', 'deleted_at' => now()]);

    // When the admin tries to change its role
    $response = $this->patchJson('/api/v1/admin/users/gone_one/role', ['role' => 'reviewer']);

    // Then it is not found
    $response->assertNotFound();
    expect(AuditLog::query()->count())->toBe(0);
});

it('requires sign-in and a confirmed email', function () {
    // Given no one is signed in
    // When a role change is attempted
    // Then it is a 401
    $this->patchJson('/api/v1/admin/users/rafi_k/role', ['role' => 'reviewer'])->assertUnauthorized();

    // Given an existing member and an admin who never confirmed their email
    User::factory()->create(['handle' => 'rafi_k']);
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    // Then they are told to confirm first
    $this->patchJson('/api/v1/admin/users/rafi_k/role', ['role' => 'reviewer'])
        ->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('blocks a suspended admin', function () {
    // Given a suspended admin and a member
    $member = User::factory()->create(['handle' => 'rafi_k']);
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());

    // When they try to change a role
    $response = $this->patchJson('/api/v1/admin/users/rafi_k/role', ['role' => 'reviewer']);

    // Then the account is unavailable and nothing changes
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect($member->fresh()->role)->toBe(Role::Member);
});

it('re-checks everything under lock: a stale actor, a stale target, a deleted target', function () {
    // Given a real admin, and models that are stale copies of rows that changed in the meantime
    User::factory()->role(Role::Admin)->create();
    $staleActor = User::factory()->role(Role::Admin)->create();
    $staleActor->forceFill(['role' => Role::Member])->saveQuietly(); // demoted/deleted since loaded
    $staleActor->forceFill(['role' => Role::Admin]); // the loaded copy still claims admin
    $member = User::factory()->create();
    $promoted = User::factory()->create();
    $deleted = User::factory()->create();
    $action = app(ChangeUserRole::class);

    // When a target became an admin after it was loaded (stale copy says member)
    $realAdmin = User::query()->where('role', 'admin')->firstOrFail();
    $promoted->forceFill(['role' => Role::Admin])->saveQuietly();
    $promoted->forceFill(['role' => Role::Member]);
    // Then a live admin may not change it
    expect(fn () => $action->handle($realAdmin, $promoted, Role::Reviewer))->toThrow(AuthorizationException::class);
    expect($promoted->fresh()->role)->toBe(Role::Admin);

    // And an actor who is no longer an admin may not change anyone
    expect(fn () => $action->handle($staleActor, $member, Role::Reviewer))->toThrow(AuthorizationException::class);
    expect($member->fresh()->role)->toBe(Role::Member);

    // And a target deleted after it was loaded is not found, and stays untouched
    $deleted->forceFill(['deleted_at' => now()])->saveQuietly();
    $deleted->forceFill(['deleted_at' => null]);
    expect(fn () => $action->handle($realAdmin, $deleted, Role::Reviewer))->toThrow(ModelNotFoundException::class);
    expect($deleted->fresh()->role)->toBe(Role::Member);
    expect(AuditLog::query()->count())->toBe(0);
});

it('never lets an admin change their own role, even when called directly', function () {
    // Given the only admin
    $admin = User::factory()->role(Role::Admin)->create();

    // When the action is asked to demote them
    // Then it refuses and the admin stays
    expect(fn () => app(ChangeUserRole::class)->handle($admin, $admin, Role::Member))
        ->toThrow(AuthorizationException::class);
    expect($admin->fresh()->role)->toBe(Role::Admin);
});

it('refuses non-admins with 403 before validating the payload', function (Role $role) {
    // Given a non-admin and a member
    User::factory()->create(['handle' => 'rafi_k']);
    Sanctum::actingAs(User::factory()->role($role)->create());

    // When they send an invalid role
    $response = $this->patchJson('/api/v1/admin/users/rafi_k/role', ['role' => 'wizard']);

    // Then authorisation wins over validation
    $response->assertForbidden();
})->with([Role::Reviewer, Role::Member]);
