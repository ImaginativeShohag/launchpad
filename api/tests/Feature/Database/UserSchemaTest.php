<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('stores users with ULID ids, a lowercase email and a lowercase handle', function () {
    // Given a user created with mixed-case, padded input
    $user = User::factory()->create(['email' => '  Amina@Example.COM ', 'handle' => 'Amina_R']);

    // When it is reloaded from the database
    $fresh = User::query()->findOrFail($user->id);

    // Then the id is a ULID and email/handle are normalised
    expect(Str::isUlid($fresh->id))->toBeTrue();
    expect($fresh->email)->toBe('amina@example.com');
    expect($fresh->handle)->toBe('amina_r');
    expect($fresh->role)->toBe(Role::Member);
    expect($fresh->getRouteKeyName())->toBe('handle');
});

it('rejects an unknown role at the database level', function () {
    // Given a row with an invalid role
    $row = [
        'id' => (string) Str::ulid(), 'handle' => 'wizard', 'name' => 'Wizard',
        'email' => 'wizard@example.com', 'role' => 'wizard', 'created_at' => now(),
    ];

    // When it is inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('users')->insert($row));

    // Then the CHECK constraint refuses it
    expect($insert)->toThrow(QueryException::class);
});

it('rejects a mixed-case email at the database level', function () {
    // Given a row with an uppercase email
    $row = [
        'id' => (string) Str::ulid(), 'handle' => 'shouty', 'name' => 'Shouty',
        'email' => 'Shouty@Example.com', 'created_at' => now(),
    ];

    // When it is inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('users')->insert($row));

    // Then the lowercase CHECK refuses it
    expect($insert)->toThrow(QueryException::class);
});

it('allows a user without a password (Google-only account)', function () {
    // Given / When a user is created with no password
    $user = User::factory()->withoutPassword()->create();

    // Then it is stored and reports having no password
    expect($user->fresh()->hasPassword())->toBeFalse();
});
