<?php

use App\Actions\Auth\DeleteAccount;
use App\Enums\Role;
use App\Jobs\DeleteMemberComments;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/FollowFixtures.php';
require_once __DIR__.'/../../Support/CommentFixtures.php';

afterEach(fn () => AuditLog::flushEventListeners());

it('deletes the follows of a deleted account in both directions and its topic follows, leaving others intact', function () {
    // Given a member who follows two people, is followed by one, and follows a topic; plus unrelated follows
    Queue::fake();
    $leaver = User::factory()->create();
    [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();
    $topic = Topic::factory()->create();
    followRow($leaver, $a);
    followRow($leaver, $b);
    followRow($c, $leaver);
    followRow($c, $a);
    followRow($a, $d);
    DB::table('topic_follows')->insert([['user_id' => $leaver->id, 'topic_id' => $topic->id], ['user_id' => $a->id, 'topic_id' => $topic->id]]);

    // When the account is deleted
    expect(app(DeleteAccount::class)->handle($leaver))->toBeTrue();

    // Then every follow of the leaver is gone, and the others stay
    expect(DB::table('user_follows')->where('follower_id', $leaver->id)->orWhere('followed_id', $leaver->id)->count())->toBe(0);
    expect(DB::table('user_follows')->count())->toBe(2)->and(followCount($c, $a))->toBe(1)->and(followCount($a, $d))->toBe(1);
    expect(DB::table('topic_follows')->where('user_id', $leaver->id)->count())->toBe(0)->and(DB::table('topic_follows')->where('user_id', $a->id)->count())->toBe(1);
});

it('removes mutual follows without error', function () {
    // Given two members who follow each other, one of them deleting
    Queue::fake();
    $a = User::factory()->create();
    $b = User::factory()->create();
    followRow($a, $b);
    followRow($b, $a);

    // When the first deletes their account
    app(DeleteAccount::class)->handle($a);

    // Then both directions are gone
    expect(DB::table('user_follows')->count())->toBe(0);
});

it('locks the follow rows in (follower_id, followed_id) order before deleting them', function () {
    // Given a member with follows in both directions
    Queue::fake();
    $leaver = User::factory()->create();
    followRow($leaver, User::factory()->create());
    followRow(User::factory()->create(), $leaver);
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, '"user_follows"')) {
            $log[] = $sql;
        }
    });

    // When the account is deleted
    app(DeleteAccount::class)->handle($leaver);

    // Then the locking select (ordered, FOR UPDATE) comes before the delete
    expect($log[0])->toContain('select')->toContain('order by "follower_id" asc, "followed_id" asc')->toContain('for update');
    expect($log[1])->toStartWith('delete from "user_follows"');
});

it('deletes nothing when the deletion is refused', function (string $reason) {
    // Given a member with follows who cannot delete: a live product, or the last admin
    Queue::fake();
    $member = User::factory()->lastLoginMinutesAgo(1)->create(['role' => $reason === 'last admin' ? Role::Admin : Role::Member]);
    $other = User::factory()->create();
    $topic = Topic::factory()->create();
    followRow($member, $other);
    followRow($other, $member);
    DB::table('topic_follows')->insert(['user_id' => $member->id, 'topic_id' => $topic->id]);
    if ($reason === 'live product') {
        Product::factory()->live()->create(['maker_id' => $member->id]);
    }
    Sanctum::actingAs($member);

    // When they try to delete the account
    $response = $this->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);

    // Then it is refused (a live product `has_active_product`, the last admin `last_admin`: both 403) and every follow is still there
    $response->assertForbidden()->assertJsonPath('code', $reason === 'last admin' ? 'last_admin' : 'has_active_product');
    expect(DB::table('user_follows')->count())->toBe(2)->and(DB::table('topic_follows')->count())->toBe(1);
})->with(['live product', 'last admin']);

it('leaves a deleted member\'s comment counters alone in the transaction: with the queue faked, nothing is touched and the job is only queued', function () {
    // Given a member with a visible comment on someone else's product, counters set to match
    Queue::fake();
    $leaver = User::factory()->create();
    $product = commentable();
    Comment::factory()->create(['product_id' => $product->id, 'user_id' => $leaver->id]);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1]);

    // When the account is deleted
    app(DeleteAccount::class)->handle($leaver);

    // Then the counter is untouched (correcting it needs the product row, which DeleteAccount must never take: global
    // lock order) and the clean-up job was queued instead; until it runs the reads mask the author
    expect(productCommentCounters($product))->toBe([1, 1]);
    Queue::assertPushed(DeleteMemberComments::class, fn (DeleteMemberComments $job) => $job->userId === $leaver->id);
});

it('corrects a deleted member\'s comment counters end to end once the clean-up job has run', function () {
    // Given the same member and comment, and the sync queue (the test default), so the job runs after the commit
    $leaver = User::factory()->create();
    $product = commentable();
    $comment = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $leaver->id, 'body' => 'Personal words']);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1]);

    // When the account is deleted
    app(DeleteAccount::class)->handle($leaver);

    // Then the ghost is gone: the body is wiped and the counter is corrected
    expect(productCommentCounters($product))->toBe([0, 0]);
    expect($comment->fresh()->body)->toBe('');
});

it('deletes an unused topic together with its followers and audits it', function () {
    // Given an admin and an unused topic with two followers
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create(['slug' => 'gone']);
    DB::table('topic_follows')->insert([['user_id' => User::factory()->create()->id, 'topic_id' => $topic->id], ['user_id' => User::factory()->create()->id, 'topic_id' => $topic->id]]);

    // When the topic is deleted
    $this->deleteJson("/api/v1/admin/topics/{$topic->id}")->assertNoContent();

    // Then the followers and the topic are gone and the audit row was written
    expect(Topic::query()->count())->toBe(0)->and(DB::table('topic_follows')->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'topic.deleted')->count())->toBe(1);
});

it('keeps the followers of a topic that is still in use: 409 topic_in_use', function () {
    // Given a topic used by a product and followed by a member
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create();
    DB::table('product_topics')->insert(['product_id' => Product::factory()->create()->id, 'topic_id' => $topic->id]);
    DB::table('topic_follows')->insert(['user_id' => User::factory()->create()->id, 'topic_id' => $topic->id]);

    // When the topic is deleted
    $response = $this->deleteJson("/api/v1/admin/topics/{$topic->id}");

    // Then it is refused and the follower is still there
    $response->assertStatus(409)->assertJsonPath('code', 'topic_in_use');
    expect(DB::table('topic_follows')->count())->toBe(1)->and(Topic::query()->count())->toBe(1);
});

it('rolls the follow deletion back when the audit write fails', function () {
    // Given an admin, a topic with a follower, and an audit insert that fails
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create();
    DB::table('topic_follows')->insert(['user_id' => User::factory()->create()->id, 'topic_id' => $topic->id]);
    AuditLog::creating(function () {
        throw new RuntimeException('audit down');
    });
    $this->withoutExceptionHandling();

    // When the topic is deleted
    // Then the request fails and both the follows and the topic are still there
    expect(fn () => $this->deleteJson("/api/v1/admin/topics/{$topic->id}"))->toThrow(RuntimeException::class);
    expect(DB::table('topic_follows')->count())->toBe(1)->and(Topic::query()->count())->toBe(1);
});

it('writes the topic.deleted audit row BEFORE it deletes the topic\'s follows (a user row is locked before follow rows)', function () {
    // Given an admin and an unused topic with a follower
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create();
    DB::table('topic_follows')->insert(['user_id' => User::factory()->create()->id, 'topic_id' => $topic->id]);
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $log[] = strtolower($query->sql);
    });

    // When the topic is deleted
    $this->deleteJson("/api/v1/admin/topics/{$topic->id}")->assertNoContent();

    // Then the audit insert precedes the follow deletion, which precedes the topic deletion
    $audit = collect($log)->search(fn (string $sql) => str_starts_with($sql, 'insert into "audit_logs"'));
    $follows = collect($log)->search(fn (string $sql) => str_starts_with($sql, 'delete from "topic_follows"'));
    $topicDelete = collect($log)->search(fn (string $sql) => str_starts_with($sql, 'delete from "topics"'));
    expect($audit)->not->toBeFalse()->and($follows)->not->toBeFalse()->and($topicDelete)->not->toBeFalse();
    expect($audit)->toBeLessThan($follows)->and($follows)->toBeLessThan($topicDelete);
});
