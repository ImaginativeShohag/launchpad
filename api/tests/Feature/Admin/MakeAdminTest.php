<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;

it('promotes an existing member to admin and audits it as a system action', function () {
    // Given a member
    $user = User::factory()->create(['email' => 'boss@example.com']);

    // When the command runs
    $this->artisan('launchpad:make-admin', ['email' => 'Boss@Example.com'])->assertExitCode(0);

    // Then they are an admin and the audit row has no human actor
    expect($user->fresh()->role)->toBe(Role::Admin);
    $log = AuditLog::query()->where('action', 'user.role_changed')->firstOrFail();
    expect($log->actor_id)->toBeNull()->and($log->subject_id)->toBe($user->id);
    expect($log->meta)->toMatchArray(['before' => 'member', 'after' => 'admin', 'via' => 'artisan']);
});

it('fails for an unknown email and does nothing for an existing admin', function () {
    // Given an existing admin
    User::factory()->role(Role::Admin)->create(['email' => 'boss@example.com']);

    // When the command runs for an unknown email, and for the admin
    $this->artisan('launchpad:make-admin', ['email' => 'ghost@example.com'])->assertExitCode(1);
    $this->artisan('launchpad:make-admin', ['email' => 'boss@example.com'])->assertExitCode(0);

    // Then nothing is audited
    expect(AuditLog::query()->count())->toBe(0);
});

it('refuses unverified, suspended and deleted accounts without changing them', function (string $state) {
    // Given an account in a state that must not become an admin
    $factory = User::factory()->state(['email' => 'boss@example.com']);
    $user = match ($state) {
        'unverified' => $factory->unverified()->create(),
        'suspended' => $factory->suspended()->create(),
        'deleted' => $factory->state(['deleted_at' => now()])->create(),
    };

    // When the command runs
    $this->artisan('launchpad:make-admin', ['email' => 'boss@example.com'])->assertExitCode(1);

    // Then the account is unchanged and nothing is audited
    expect($user->fresh()->role)->toBe(Role::Member);
    expect(AuditLog::query()->count())->toBe(0);
})->with(['unverified', 'suspended', 'deleted']);

it('never creates users and never prints the email or secrets', function () {
    // Given no users
    // When the command runs for an unknown email
    $this->artisan('launchpad:make-admin', ['email' => 'ghost@example.com'])
        ->doesntExpectOutputToContain('ghost@example.com')
        ->assertExitCode(1);

    // Then no user exists
    expect(User::query()->count())->toBe(0);
});

it('rejects a missing or blank email', function () {
    // Given a member
    User::factory()->create(['email' => 'boss@example.com']);

    // When the email is blank
    $this->artisan('launchpad:make-admin', ['email' => '   '])->assertExitCode(1);

    // Then nothing happens
    expect(AuditLog::query()->count())->toBe(0);
});
