<?php

use App\Actions\Follow\FollowMember;
use App\Actions\Follow\UnfollowMember;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/FollowFixtures.php';

it('follows a member: 200 with the state and the public count, one row', function () {
    // Given a member and somebody to follow
    $me = signedInMember();
    $target = User::factory()->create(['handle' => 'amina_r']);

    // When they follow by handle (the lookup ignores case)
    $response = $this->putJson('/api/v1/users/AMINA_R/follow');

    // Then the answer is the state and the count, and there is one row
    $response->assertOk()->assertExactJson(['data' => ['following' => true, 'followers_count' => 1]]);
    expect(followCount($me, $target))->toBe(1);
});

it('is idempotent: a repeat follow answers the same and writes nothing', function () {
    // Given a follow made once
    $me = signedInMember();
    $target = User::factory()->create();
    $this->putJson("/api/v1/users/{$target->handle}/follow")->assertOk();
    $createdAt = DB::table('user_follows')->value('created_at');

    // When it is repeated through the HTTP API and through the action
    $again = $this->putJson("/api/v1/users/{$target->handle}/follow");
    $result = app(FollowMember::class)->handle($me, $target);

    // Then the body is identical, nothing changed, and the row (with its instant) is the same single row
    $again->assertOk()->assertExactJson(['data' => ['following' => true, 'followers_count' => 1]]);
    expect($result->changed)->toBeFalse()->and($result->following)->toBeTrue()->and($result->followersCount)->toBe(1);
    expect(DB::table('user_follows')->count())->toBe(1)->and(DB::table('user_follows')->value('created_at'))->toBe($createdAt);
});

it('reports a change only for the first follow and the first unfollow (action level)', function () {
    // Given two members
    $me = User::factory()->create();
    $target = User::factory()->create();

    // When they follow, follow again, unfollow, unfollow again
    $results = [
        app(FollowMember::class)->handle($me, $target)->changed,
        app(FollowMember::class)->handle($me, $target)->changed,
        app(UnfollowMember::class)->handle($me, $target)->changed,
        app(UnfollowMember::class)->handle($me, $target)->changed,
    ];

    // Then only the first of each pair changed anything
    expect($results)->toBe([true, false, true, false]);
});

it('unfollows: 200, the row is gone, a repeat is a no-op 200', function () {
    // Given a follow
    $me = signedInMember();
    $target = User::factory()->create();
    followRow($me, $target);

    // When the member unfollows twice
    $first = $this->deleteJson("/api/v1/users/{$target->handle}/follow");
    $second = $this->deleteJson("/api/v1/users/{$target->handle}/follow");

    // Then both answer the same state and no row remains
    $first->assertOk()->assertExactJson(['data' => ['following' => false, 'followers_count' => 0]]);
    $second->assertOk()->assertExactJson(['data' => ['following' => false, 'followers_count' => 0]]);
    expect(DB::table('user_follows')->count())->toBe(0);
});

it('counts every follower of the target in the answer', function () {
    // Given a member who already has two followers
    $target = User::factory()->create();
    followRow(User::factory()->create(), $target);
    followRow(User::factory()->create(), $target);
    signedInMember();

    // When a third follows
    // Then the count includes them
    $this->putJson("/api/v1/users/{$target->handle}/follow")->assertOk()->assertJsonPath('data.followers_count', 3);
});

it('refuses to follow or unfollow oneself with 403 self_follow', function (string $verb) {
    // Given a member
    $me = signedInMember();

    // When they follow or unfollow themselves
    $response = $this->json($verb, "/api/v1/users/{$me->handle}/follow");

    // Then it is 403 with a stable code and no row exists
    $response->assertForbidden()->assertJsonPath('code', 'self_follow');
    expect(DB::table('user_follows')->count())->toBe(0);
})->with(['PUT', 'DELETE']);

it('answers the identical 404 for unknown, deleted and odd handles', function (string $handle, string $verb) {
    // Given a deleted member and a signed-in member
    User::factory()->create(['handle' => 'gone', 'deleted_at' => now()]);
    signedInMember();

    // When they follow or unfollow it
    $response = $this->json($verb, '/api/v1/users/'.$handle.'/follow');

    // Then it is the plain JSON 404, never a 500, and nothing was written
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(DB::table('user_follows')->count())->toBe(0);
})->with(fn () => collect(['gone', 'nobody', 'a%00b', '%C3%BC', str_repeat('a', 200)])->crossJoin(['PUT', 'DELETE'])->all());

it('answers 400 for invalid UTF-8 in the handle, never a 500', function () {
    // Given a signed-in member
    signedInMember();

    // When the handle has invalid UTF-8 bytes
    // Then Symfony rejects the malformed path itself
    $this->putJson('/api/v1/users/%FF%FE/follow')->assertStatus(400);
});

it('refuses a new follow of a suspended member with 409 member_unavailable, but lets an existing one be removed', function () {
    // Given a member who follows somebody who later got suspended, and another suspended member
    $me = signedInMember();
    $suspended = User::factory()->suspended()->create();
    $other = User::factory()->suspended()->create();
    followRow($me, $suspended);

    // When they follow the other suspended member and unfollow the first
    $follow = $this->putJson("/api/v1/users/{$other->handle}/follow");
    $unfollow = $this->deleteJson("/api/v1/users/{$suspended->handle}/follow");

    // Then the follow is 409, the unfollow works
    $follow->assertStatus(409)->assertJsonPath('code', 'member_unavailable');
    $unfollow->assertOk()->assertJsonPath('data.following', false);
    expect(DB::table('user_follows')->count())->toBe(0);
});

it('lets members follow reviewers and admins', function (Role $role) {
    // Given a staff member
    $me = signedInMember();
    $staff = User::factory()->role($role)->create();

    // When they follow them
    // Then it works like any follow
    $this->putJson("/api/v1/users/{$staff->handle}/follow")->assertOk()->assertJsonPath('data.following', true);
    expect(followCount($me, $staff))->toBe(1);
})->with([[Role::Reviewer], [Role::Admin]]);

it('throws for a stale actor model suspended or deleted between the middleware and the lock', function (string $state) {
    // Given an actor whose model is stale: the account changed after the request was authorised
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $state === 'suspended' ? DB::table('users')->where('id', $actor->id)->update(['suspended_at' => now()]) : DB::table('users')->where('id', $actor->id)->update(['deleted_at' => now()]);

    // When the actions run
    // Then each refuses with an authorisation error and nothing is written
    expect(fn () => app(FollowMember::class)->handle($actor, $target))->toThrow(AuthorizationException::class);
    expect(fn () => app(UnfollowMember::class)->handle($actor, $target))->toThrow(AuthorizationException::class);
    expect(DB::table('user_follows')->count())->toBe(0);
})->with(['suspended', 'deleted']);

it('treats a target deleted between the binding and the lock as a 404, writing nothing', function () {
    // Given a target whose account was deleted after the model was loaded
    $actor = User::factory()->create();
    $target = User::factory()->create();
    DB::table('users')->where('id', $target->id)->update(['deleted_at' => now()]);

    // When the action runs with the stale model
    // Then it is a not-found error and there is no row (never a foreign key error)
    expect(fn () => app(FollowMember::class)->handle($actor, $target))->toThrow(ModelNotFoundException::class);
    expect(DB::table('user_follows')->count())->toBe(0);
});

it('applies the route guards: guest 401, unverified 403, suspended 403', function () {
    // Given a target
    $target = User::factory()->create();

    // When a guest, an unverified member and a suspended member follow
    $guest = $this->putJson("/api/v1/users/{$target->handle}/follow");
    Sanctum::actingAs(User::factory()->unverified()->create());
    $unverified = $this->putJson("/api/v1/users/{$target->handle}/follow");
    Sanctum::actingAs(User::factory()->suspended()->create());
    $suspended = $this->deleteJson("/api/v1/users/{$target->handle}/follow");

    // Then the guards answer before anything else
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect(DB::table('user_follows')->count())->toBe(0);
});

it('ignores array, object, null and scalar bodies: 200, never 500', function (mixed $body) {
    // Given a member and a target
    $me = signedInMember();
    $target = User::factory()->create();

    // When follow and unfollow are sent with a hostile JSON body
    $follow = $this->putJson("/api/v1/users/{$target->handle}/follow", is_array($body) ? $body : ['x' => $body]);
    $unfollow = $this->deleteJson("/api/v1/users/{$target->handle}/follow", is_array($body) ? $body : ['x' => $body]);

    // Then the body is never read
    $follow->assertOk();
    $unfollow->assertOk();
})->with([[['a', 'b']], [['x' => ['y' => 1]]], [[null]], ['text'], [42]]);

it('answers 405 to GET and HEAD on the follow URL', function () {
    // Given a member and a target
    signedInMember();
    $target = User::factory()->create();

    // When the follow URL is read
    // Then reading is not allowed (no follower list exists)
    $this->getJson("/api/v1/users/{$target->handle}/follow")->assertStatus(405);
});

it('never exposes follower lists: no route for followers, following or follows of a member', function (string $suffix) {
    // Given a signed-in member and a target
    signedInMember();
    $target = User::factory()->create();

    // When a list URL is requested
    // Then there is no such route
    $this->getJson("/api/v1/users/{$target->handle}/{$suffix}")->assertNotFound();
})->with(['followers', 'following', 'follows']);

it('sends no-store, private on the follow writes', function () {
    // Given a member
    signedInMember();
    $target = User::factory()->create();

    // When follow, unfollow and a refusal are sent
    $follow = $this->putJson("/api/v1/users/{$target->handle}/follow");
    $unfollow = $this->deleteJson("/api/v1/users/{$target->handle}/follow");
    $missing = $this->putJson('/api/v1/users/nobody/follow');

    // Then every answer is no-store
    foreach ([$follow, $unfollow, $missing] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('throttles follows at 20 per minute with Retry-After', function () {
    // Given a member who spent the 20 follow calls of the minute
    signedInMember();
    $target = User::factory()->create();
    foreach (range(1, 20) as $_) {
        $this->putJson("/api/v1/users/{$target->handle}/follow")->assertOk();
    }

    // When the 21st arrives, as a follow and as an unfollow
    // Then both are throttled (one shared bucket)
    $this->putJson("/api/v1/users/{$target->handle}/follow")->assertStatus(429)->assertHeader('Retry-After');
    $this->deleteJson("/api/v1/users/{$target->handle}/follow")->assertStatus(429);
});
