<?php

use App\Actions\Follow\FollowMember;
use App\Actions\Follow\FollowTopic;
use App\Actions\Follow\UnfollowMember;
use App\Actions\Follow\UnfollowTopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/FollowFixtures.php';

it('takes both user rows FOR SHARE in one statement in ascending id order, with no FOR UPDATE and no product query', function () {
    // Given two members (the actor's id may sort either way, so try both directions)
    [$low, $high] = User::factory()->count(2)->create()->sortBy('id')->values()->all();

    foreach ([[$low, $high], [$high, $low]] as [$actor, $target]) {
        DB::table('user_follows')->delete();

        // When the follow runs
        $log = followRecordLocks(fn () => app(FollowMember::class)->handle($actor, $target));

        // Then one shared lock statement covers both ids, the ids are bound in ascending order, and nothing else locks
        $shares = array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'for share')));
        expect($shares)->toHaveCount(1)->and($shares[0])->toContain('from "users"')->toContain('order by "id" asc');
        expect(collect($log)->contains(fn (string $sql) => str_contains($sql, 'for update')))->toBeFalse();
        expect(collect($log)->contains(fn (string $sql) => str_contains($sql, 'from "products"')))->toBeFalse();
    }
});

it('binds the two ids of the shared lock in ascending order whichever member is the actor', function (bool $actorIsLower) {
    // Given two members, the actor sorting below or above the target
    [$low, $high] = User::factory()->count(2)->create()->sortBy('id')->values()->all();
    [$actor, $target] = $actorIsLower ? [$low, $high] : [$high, $low];
    $bindings = null;
    DB::listen(function ($query) use (&$bindings) {
        if (str_contains(strtolower($query->sql), 'for share') && str_contains($query->sql, '"users"')) {
            $bindings = $query->bindings;
        }
    });

    // When the follow runs
    app(FollowMember::class)->handle($actor, $target);

    // Then the ids were bound in ascending order (with the ORDER BY id in the statement, the lock order is fixed)
    expect($bindings)->toBe([$low->id, $high->id]);
})->with([true, false]);

it('locks the topic row FOR SHARE before the user row FOR SHARE on a topic follow', function () {
    // Given a member and a topic
    $me = User::factory()->create();
    Topic::factory()->create(['slug' => 'dev-tools']);

    // When they follow it
    $log = followRecordLocks(fn () => app(FollowTopic::class)->handle($me, 'dev-tools'));

    // Then the topic is shared-locked first, then the user, and nothing is locked FOR UPDATE
    $targets = array_map(fn (string $sql) => str_contains($sql, 'from "topics"') ? 'topics' : (str_contains($sql, 'from "users"') ? 'users' : 'other'), array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'for share'))));
    expect($targets)->toBe(['topics', 'users']);
    expect(collect($log)->contains(fn (string $sql) => str_contains($sql, 'for update')))->toBeFalse();
});

it('locks the topic row FOR SHARE before the user row FOR SHARE on a topic unfollow too', function () {
    // Given a member following a topic
    $me = User::factory()->create();
    $topic = Topic::factory()->create(['slug' => 'dev-tools']);
    DB::table('topic_follows')->insert(['user_id' => $me->id, 'topic_id' => $topic->id]);

    // When they unfollow it
    $log = followRecordLocks(fn () => app(UnfollowTopic::class)->handle($me, 'dev-tools'));

    // Then the topic is shared-locked first, then the user, and nothing is locked FOR UPDATE
    $targets = array_map(fn (string $sql) => str_contains($sql, 'from "topics"') ? 'topics' : (str_contains($sql, 'from "users"') ? 'users' : 'other'), array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'for share'))));
    expect($targets)->toBe(['topics', 'users']);
    expect(collect($log)->contains(fn (string $sql) => str_contains($sql, 'for update')))->toBeFalse();
});

it('shared-locks only the actor on an unfollow', function () {
    // Given a follow
    [$a, $b] = User::factory()->count(2)->create()->all();
    followRow($a, $b);

    // When it is removed
    $log = followRecordLocks(fn () => app(UnfollowMember::class)->handle($a, $b));

    // Then one shared lock on users, no update lock, no product query
    expect($log)->toHaveCount(1)->and($log[0])->toContain('from "users"')->toContain('for share');
});
