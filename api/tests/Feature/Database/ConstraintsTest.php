<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('rejects an unknown link kind', function () {
    // Given a user and a link row with an invalid kind
    $user = User::factory()->create();
    $row = ['id' => (string) Str::ulid(), 'user_id' => $user->id, 'kind' => 'myspace',
        'url' => 'https://example.com', 'position' => 0];

    // When inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('user_links')->insert($row));

    // Then the CHECK constraint refuses it
    expect($insert)->toThrow(QueryException::class);
});

it('rejects an unknown auth event', function () {
    // Given an auth event row with an invalid event name
    $row = ['id' => (string) Str::ulid(), 'event' => 'teleported', 'created_at' => now()];

    // When inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('auth_events')->insert($row));

    // Then the CHECK constraint refuses it
    expect($insert)->toThrow(QueryException::class);
});

it('allows a Google identity to be linked to only one user', function () {
    // Given a Google identity already linked to a user
    SocialAccount::factory()->create(['provider' => 'google', 'provider_user_id' => 'g-1']);

    // When the same identity is linked to another user
    $second = fn () => DB::transaction(
        fn () => SocialAccount::factory()->create(['provider' => 'google', 'provider_user_id' => 'g-1'])
    );

    // Then the unique index refuses it
    expect($second)->toThrow(QueryException::class);
});
