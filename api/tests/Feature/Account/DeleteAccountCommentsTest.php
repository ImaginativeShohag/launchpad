<?php

use App\Actions\Auth\DeleteAccount;
use App\Actions\Comment\CreateComment;
use App\Enums\AuthEventType;
use App\Enums\Role;
use App\Jobs\DeleteMemberComments;
use App\Jobs\VoidMemberVotes;
use App\Models\AuditLog;
use App\Models\AuthEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';
require_once __DIR__.'/../../Support/FollowFixtures.php';

afterEach(fn () => AuditLog::flushEventListeners());

function accountCommentsDelete()
{
    return test()->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);
}

it('dispatches the comment clean-up once, with the user id, in addition to the vote clean-up', function () {
    // Given a member with no products and a fake queue
    Queue::fake();
    $user = tap(User::factory()->create(), fn (User $user) => Sanctum::actingAs($user));

    // When they delete their account
    accountCommentsDelete()->assertNoContent();

    // Then both jobs were pushed exactly once for them
    Queue::assertPushed(DeleteMemberComments::class, 1);
    Queue::assertPushed(DeleteMemberComments::class, fn (DeleteMemberComments $job) => $job->userId === $user->id);
    Queue::assertPushed(VoidMemberVotes::class, 1);
});

it('dispatches nothing when the deletion is refused', function (string $reason) {
    // Given a member who cannot delete (a live product, or the last admin) and a fake queue
    Queue::fake();
    $user = User::factory()->lastLoginMinutesAgo(1)->create(['role' => $reason === 'last_admin' ? Role::Admin : Role::Member]);
    Sanctum::actingAs($user);
    if ($reason === 'has_active_product') {
        Product::factory()->live()->create(['maker_id' => $user->id]);
    }

    // When they try to delete their account
    accountCommentsDelete()->assertForbidden()->assertJsonPath('code', $reason);

    // Then neither job was pushed
    Queue::assertNothingPushed();
})->with(['has_active_product', 'last_admin']);

it('dispatches nothing on the duplicate-request path', function () {
    // Given a member whose account a concurrent request already deleted (this model is stale)
    Queue::fake();
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);
    app(DeleteAccount::class)->handle($user);
    Queue::assertPushed(DeleteMemberComments::class, 1);

    // When the second request runs
    $second = app(DeleteAccount::class)->handle($stale);

    // Then it reports "not deleted by me" and pushed nothing more
    expect($second)->toBeFalse();
    Queue::assertPushed(DeleteMemberComments::class, 1);
});

it('reports a failing dispatch without undoing the deletion', function () {
    // Given a member and a queue that throws on every push
    Exceptions::fake();
    $user = User::factory()->create();
    $down = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $down->shouldReceive('push')->andThrow(new RuntimeException('queue down'));
    Queue::shouldReceive('connection')->andReturn($down);

    // When the account is deleted
    $deleted = app(DeleteAccount::class)->handle($user);

    // Then the deletion stands and is logged, and both failures were reported (the votes job and the comments job)
    expect($deleted)->toBeTrue()->and($user->fresh()->deleted_at)->not->toBeNull();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::AccountDeleted)->exists())->toBeTrue();
    Exceptions::assertReportedCount(2);
});

it('erases the comments and corrects the counters end to end on the sync queue, and the follows are gone too', function () {
    // Given a member with a comment on someone else's product, a reply from another member, and a follow
    $leaver = User::factory()->create();
    $other = User::factory()->create();
    $product = commentable();
    $top = app(CreateComment::class)->handle($leaver, $product->id, 'Personal words by the leaver', null);
    app(CreateComment::class)->handle($other, $product->id, 'A reply from somebody else', $top->id);
    app(CreateComment::class)->handle($leaver, $product->id, 'Another top level one', null);
    followRow($leaver, $other);
    expect(productCommentCounters($product))->toBe([3, 3]);

    // When the account is deleted (the test queue is sync, so the job runs after the commit)
    app(DeleteAccount::class)->handle($leaver);

    // Then the leaver's bodies are gone, the counters are corrected, the other member's reply is intact, and the follows are gone
    expect(DB::table('comments')->where('user_id', $leaver->id)->pluck('body')->all())->toBe(['', '']);
    expect(productCommentCounters($product))->toBe([1, 1]);
    expect(DB::table('comments')->where('id', $top->id)->value('replies_count'))->toBe(1);
    expect(DB::table('comments')->where('user_id', $other->id)->value('body'))->toBe('A reply from somebody else');
    expect(DB::table('user_follows')->count())->toBe(0);
    assertCommentCountersConsistent();
});
