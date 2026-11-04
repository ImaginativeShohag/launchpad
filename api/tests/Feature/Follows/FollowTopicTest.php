<?php

use App\Actions\Follow\FollowTopic;
use App\Actions\Follow\UnfollowTopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/FollowFixtures.php';

it('follows a topic by slug: 200 with the state and the topic follower count, one row', function () {
    // Given a topic that already has one follower
    $me = signedInMember();
    $topic = Topic::factory()->create(['slug' => 'dev-tools']);
    DB::table('topic_follows')->insert(['user_id' => User::factory()->create()->id, 'topic_id' => $topic->id]);

    // When the member follows it
    $response = $this->putJson('/api/v1/topics/dev-tools/follow');

    // Then the count includes both followers and the member has one row
    $response->assertOk()->assertExactJson(['data' => ['following' => true, 'followers_count' => 2]]);
    expect(DB::table('topic_follows')->where('user_id', $me->id)->count())->toBe(1);
});

it('is idempotent in both directions', function () {
    // Given a member and a topic
    $me = signedInMember();
    $topic = Topic::factory()->create(['slug' => 'dev-tools']);

    // When they follow twice and unfollow twice
    $f1 = $this->putJson('/api/v1/topics/dev-tools/follow');
    $f2 = $this->putJson('/api/v1/topics/dev-tools/follow');
    $rowsAfterFollow = DB::table('topic_follows')->count();
    $u1 = $this->deleteJson('/api/v1/topics/dev-tools/follow');
    $u2 = $this->deleteJson('/api/v1/topics/dev-tools/follow');

    // Then the repeats answer the same state and change nothing
    $f1->assertOk()->assertJsonPath('data.following', true);
    $f2->assertOk()->assertExactJson($f1->json());
    expect($rowsAfterFollow)->toBe(1);
    $u1->assertOk()->assertExactJson(['data' => ['following' => false, 'followers_count' => 0]]);
    $u2->assertOk()->assertExactJson($u1->json());
    expect(DB::table('topic_follows')->count())->toBe(0);
});

it('reports a change only for the first follow and first unfollow (action level)', function () {
    // Given a member and a topic
    $me = User::factory()->create();
    Topic::factory()->create(['slug' => 'dev-tools']);

    // When they follow, follow, unfollow, unfollow
    $changed = [
        app(FollowTopic::class)->handle($me, 'dev-tools')->changed,
        app(FollowTopic::class)->handle($me, 'dev-tools')->changed,
        app(UnfollowTopic::class)->handle($me, 'dev-tools')->changed,
        app(UnfollowTopic::class)->handle($me, 'dev-tools')->changed,
    ];

    // Then only the first of each pair changed anything
    expect($changed)->toBe([true, false, true, false]);
});

it('counts followers per topic', function () {
    // Given two topics and two members following the first
    $one = Topic::factory()->create(['slug' => 'one']);
    Topic::factory()->create(['slug' => 'two']);
    DB::table('topic_follows')->insert(['user_id' => User::factory()->create()->id, 'topic_id' => $one->id]);
    signedInMember();

    // When the signed-in member follows the second
    // Then its count is 1, unaffected by the first topic's followers
    $this->putJson('/api/v1/topics/two/follow')->assertOk()->assertJsonPath('data.followers_count', 1);
});

it('accepts only the exact stored slug: upper case and odd slugs are the uniform 404', function (string $slug, string $verb) {
    // Given a lowercase topic and a signed-in member
    Topic::factory()->create(['slug' => 'dev-tools']);
    signedInMember();

    // When a differently written slug is used
    $response = $this->json($verb, '/api/v1/topics/'.$slug.'/follow');

    // Then it is the plain 404 and nothing was written
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(DB::table('topic_follows')->count())->toBe(0);
})->with(fn () => collect(['DEV-TOOLS', 'unknown', 'a%00b', 'a%20b', str_repeat('a', 200), 'dev--tools', '%C3%BC'])->crossJoin(['PUT', 'DELETE'])->all());

it('answers a topic deleted between the plain read and the lock with 404, never a foreign key error', function (string $verb) {
    // Given a topic that is deleted after the slug was resolved (the action re-reads it under FOR SHARE)
    $me = User::factory()->create();
    $topic = Topic::factory()->create(['slug' => 'dev-tools']);
    if ($verb === 'unfollow') {
        DB::table('topic_follows')->insert(['user_id' => $me->id, 'topic_id' => $topic->id]);
    }
    $deleted = false;
    // (right after the plain slug read, before the action's transaction starts, so nothing is rolled back with it)
    DB::listen(function ($query) use ($topic, &$deleted) {
        if (! $deleted && str_contains(strtolower($query->sql), 'from "topics" where "slug"')) {
            $deleted = true;
            DB::connection()->getPdo()->exec("DELETE FROM topic_follows WHERE topic_id = '{$topic->id}'");
            DB::connection()->getPdo()->exec("DELETE FROM topics WHERE id = '{$topic->id}'");
        }
    });

    // When the member follows or unfollows it
    // Then it is a not-found error and nothing is left behind
    $action = $verb === 'follow' ? app(FollowTopic::class) : app(UnfollowTopic::class);
    expect(fn () => $action->handle($me, 'dev-tools'))->toThrow(ModelNotFoundException::class);
    expect($deleted)->toBeTrue()->and(DB::table('topic_follows')->count())->toBe(0);
})->with(['follow', 'unfollow']);

it('throws for a stale actor model on a topic follow', function (string $state) {
    // Given a suspended or deleted actor (stale model) and a topic
    $actor = User::factory()->create();
    Topic::factory()->create(['slug' => 'dev-tools']);
    DB::table('users')->where('id', $actor->id)->update($state === 'suspended' ? ['suspended_at' => now()] : ['deleted_at' => now()]);

    // When the actions run
    // Then each refuses and nothing is written
    expect(fn () => app(FollowTopic::class)->handle($actor, 'dev-tools'))->toThrow(AuthorizationException::class);
    expect(fn () => app(UnfollowTopic::class)->handle($actor, 'dev-tools'))->toThrow(AuthorizationException::class);
    expect(DB::table('topic_follows')->count())->toBe(0);
})->with(['suspended', 'deleted']);

it('applies the route guards on topic follows', function () {
    // Given a topic
    Topic::factory()->create(['slug' => 'dev-tools']);

    // When a guest, an unverified and a suspended member call
    $guest = $this->putJson('/api/v1/topics/dev-tools/follow');
    Sanctum::actingAs(User::factory()->unverified()->create());
    $unverified = $this->putJson('/api/v1/topics/dev-tools/follow');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $suspended = $this->deleteJson('/api/v1/topics/dev-tools/follow');

    // Then the guards answer, nothing is written
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect(DB::table('topic_follows')->count())->toBe(0);
});

it('ignores array, object, null and scalar bodies on topic follows', function (mixed $body) {
    // Given a member and a topic
    signedInMember();
    Topic::factory()->create(['slug' => 'dev-tools']);

    // When both verbs get a hostile body
    // Then the body is never read: 200, never 500
    $this->putJson('/api/v1/topics/dev-tools/follow', is_array($body) ? $body : ['x' => $body])->assertOk();
    $this->deleteJson('/api/v1/topics/dev-tools/follow', is_array($body) ? $body : ['x' => $body])->assertOk();
})->with([[['a', 'b']], [['x' => ['y' => 1]]], [[null]], ['text'], [42]]);

it('sends no-store, private on topic follow writes and answers 405 to a GET of the follow URL', function () {
    // Given a member and a topic
    signedInMember();
    Topic::factory()->create(['slug' => 'dev-tools']);

    // When follow, a refusal and a GET are sent
    $follow = $this->putJson('/api/v1/topics/dev-tools/follow');
    $missing = $this->deleteJson('/api/v1/topics/nope/follow');
    $get = $this->getJson('/api/v1/topics/dev-tools/follow');

    // Then the writes are no-store and the GET is not allowed
    foreach ([$follow, $missing] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
    $get->assertStatus(405);
});
