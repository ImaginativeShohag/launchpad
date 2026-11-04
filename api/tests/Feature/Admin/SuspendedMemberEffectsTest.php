<?php

use App\Actions\Comment\CreateComment;
use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';
require_once __DIR__.'/../../Support/FollowFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

/**
 * A member with everything public: a product launching today, a counted vote on another product, a comment, a follower and
 * a follow. Then an admin suspends them through the API.
 *
 * @return array{0: User, 1: Product, 2: Product}
 */
function suspendedMemberWorld(): array
{
    $member = User::factory()->create(['handle' => 'trouble_x', 'name' => 'Trouble X', 'headline' => 'Hello there']);
    $mine = Product::factory()->live(now('UTC')->toDateString())->create(['maker_id' => $member->id, 'slug' => 'my-launch']);
    DB::table('products')->where('id', $mine->id)->update(['votes_count' => 3]);
    $other = Product::factory()->live(now('UTC')->toDateString())->create(['slug' => 'other-launch']);
    app(CastVote::class)->handle($member, $other->id, new VoteSignals(null, null, null));
    app(CreateComment::class)->handle($member, $other->id, 'A comment by the member', null);
    $friend = User::factory()->create();
    followRow($friend, $member);
    followRow($member, $friend);

    signedInStaff(Role::Admin);
    test()->postJson('/api/v1/admin/users/trouble_x/suspend', ['reason' => 'Harassment'])->assertOk();
    app('auth')->forgetGuards();

    return [$member->fresh(), $mine, $other];
}

it('leaves the member\'s public content in place: product, votes, comments, follows and a reduced profile', function () {
    // Given a member who is suspended
    [$member, $mine, $other] = suspendedMemberWorld();

    // When a guest reads the public pages
    $today = $this->getJson('/api/v1/today')->assertOk();
    $page = $this->getJson('/api/v1/products/my-launch')->assertOk();
    $theirs = $this->getJson('/api/v1/users/trouble_x/products')->assertOk();
    $board = $this->getJson('/api/v1/leaderboards?period=all')->assertOk();
    $comments = $this->getJson('/api/v1/products/other-launch/comments')->assertOk();
    $profile = $this->getJson('/api/v1/users/trouble_x')->assertOk();

    // Then their product launches and ranks, their vote still counts, their comment lists reduced, their follows count, and the profile is reduced
    expect($today->getContent())->toContain('my-launch');
    expect($page->json('data.slug'))->toBe('my-launch')->and($page->json('data.votes_count'))->toBe(3);
    expect($theirs->getContent())->toContain('my-launch');
    expect($board->getContent())->toContain('my-launch');
    expect(DB::table('products')->where('id', $other->id)->value('votes_count'))->toBe(1)->and(DB::table('votes')->where('user_id', $member->id)->where('status', 'counted')->count())->toBe(1);
    expect($comments->json('data.0.body'))->toBe('A comment by the member')->and($comments->json('data.0.author'))->toMatchArray(['handle' => 'trouble_x', 'name' => 'Trouble X', 'avatar_url' => null, 'is_suspended' => true]);
    expect($profile->json('data.is_suspended'))->toBeTrue()->and($profile->json('data.headline'))->toBeNull()->and($profile->json('data.followers_count'))->toBe(1)->and($profile->json('data.following_count'))->toBe(1);
});

it('blocks every authenticated action of a suspended member with the same 403 for an existing and a missing id', function () {
    // Given a suspended member (the row says suspended; the stale-token case is covered by the sign-out test) and some targets
    [$member, $mine, $other] = suspendedMemberWorld();
    $comment = Comment::factory()->create(['product_id' => $other->id]);
    $topic = Topic::factory()->create(['slug' => 'real-topic']);
    $stranger = User::factory()->create(['handle' => 'stranger_x']);
    Sanctum::actingAs($member);
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When they try each action against something that exists and something that does not
    $attempts = fn (string $productId, string $commentId, string $handle, string $slug) => [
        $this->putJson("/api/v1/products/{$productId}/vote"),
        $this->deleteJson("/api/v1/products/{$productId}/vote"),
        $this->postJson("/api/v1/products/{$productId}/comments", ['body' => 'Hello there friends']),
        $this->patchJson("/api/v1/comments/{$commentId}", ['body' => 'Edited text here']),
        $this->deleteJson("/api/v1/comments/{$commentId}"),
        $this->putJson("/api/v1/users/{$handle}/follow"),
        $this->deleteJson("/api/v1/users/{$handle}/follow"),
        $this->putJson("/api/v1/topics/{$slug}/follow"),
        $this->postJson("/api/v1/comments/{$commentId}/report", ['reason' => 'spam']),
        $this->postJson("/api/v1/products/{$productId}/report", ['reason' => 'spam']),
        $this->postJson('/api/v1/products', ['name' => 'Another thing']),
        $this->patchJson("/api/v1/products/{$productId}", ['description' => 'Changed']),
        $this->postJson("/api/v1/products/{$productId}/submit"),
        $this->getJson('/api/v1/me/feed'),
    ];

    // Then every one is the guard's 403 account_unavailable, identical for existing and missing ids
    foreach ([...$attempts($other->id, $comment->id, 'stranger_x', 'real-topic'), ...$attempts($missing, $missing, 'no-such-member', 'no-such-topic')] as $response) {
        $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    }
    expect(DB::table('comments')->where('user_id', $member->id)->count())->toBe(1)->and(DB::table('products')->where('id', $other->id)->value('votes_count'))->toBe(1);
});

it('treats a suspended caller as a guest on the public reads: no has_voted, can_edit or is_following', function () {
    // Given a suspended member who commented and voted, and the same reads as a guest
    [$member, $mine, $other] = suspendedMemberWorld();
    $guestProduct = $this->getJson('/api/v1/products/other-launch')->assertOk()->json('data');
    $guestComments = $this->getJson('/api/v1/products/other-launch/comments')->assertOk()->json('data.0');
    $guestProfile = $this->getJson('/api/v1/users/stranger_x')->status();

    // When the suspended member (a stale token, or a session) reads them
    Sanctum::actingAs($member);
    $product = $this->getJson('/api/v1/products/other-launch')->assertOk();
    $comments = $this->getJson('/api/v1/products/other-launch/comments')->assertOk();
    $profile = $this->getJson('/api/v1/users/trouble_x')->assertOk();

    // Then no viewer-only key appears and the cache policy is the guest's
    expect(array_key_exists('has_voted', $product->json('data')))->toBeFalse();
    expect(array_key_exists('can_edit', $comments->json('data.0')))->toBeFalse()->and(array_key_exists('can_delete', $comments->json('data.0')))->toBeFalse();
    expect(array_key_exists('is_following', $profile->json('data')))->toBeFalse();
    expect($comments->headers->get('Cache-Control'))->not->toContain('no-store');
    expect(array_keys($guestComments))->toBe(array_keys($comments->json('data.0')))->and(array_keys($guestProduct))->toBe(array_keys($product->json('data')))->and($guestProfile)->toBe(404);
});

it('stops the old token on the very next request', function () {
    // Given a member with a device token, suspended through the API
    $member = User::factory()->create(['handle' => 'trouble_x']);
    $token = $member->createToken('phone')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    signedInStaff(Role::Admin);
    $this->postJson('/api/v1/admin/users/trouble_x/suspend', ['reason' => 'Harassment'])->assertOk();

    // When the old token is used again
    $this->app['auth']->forgetGuards();
    $response = $this->withToken($token)->getJson('/api/v1/me');

    // Then it is refused
    $response->assertUnauthorized();
});
