<?php

use App\Actions\Auth\DeleteAccount;
use App\Actions\Vote\HourlyBuckets;
use App\Enums\VoteStatus;
use App\Jobs\VoidMemberVotes;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC'));
});
afterEach(fn () => AuditLog::flushEventListeners());

function votingMember(): User
{
    return tap(User::factory()->create(), fn (User $user) => Sanctum::actingAs($user));
}

function voteOnLaunch(User $voter, int $dayOffset): array
{
    $product = Product::factory()->live(now('UTC')->addDays($dayOffset)->toDateString())->create();
    $vote = Vote::factory()->create(['user_id' => $voter->id, 'product_id' => $product->id, 'voted_at' => now()->startOfSecond()]);
    DB::table('products')->where('id', $product->id)->increment('votes_count');
    HourlyBuckets::add($product->id, $vote->voted_at, 1);

    return [$product, $vote];
}

function removeMyAccount()
{
    return test()->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);
}

it('dispatches the void job once, with the user id, when the account is deleted', function () {
    // Given a member with no products and a fake queue
    Queue::fake();
    $user = votingMember();

    // When they delete their account
    removeMyAccount()->assertNoContent();

    // Then exactly one job was pushed for them
    Queue::assertPushed(VoidMemberVotes::class, 1);
    Queue::assertPushed(VoidMemberVotes::class, fn (VoidMemberVotes $job) => $job->userId === $user->id && $job->reason === VoidMemberVotes::REASON_DELETED);
});

it('does not dispatch when the deletion is refused', function () {
    // Given a member with a live product (blocks deletion) and a fake queue
    Queue::fake();
    $user = votingMember();
    Product::factory()->live()->create(['maker_id' => $user->id]);

    // When they try to delete their account
    removeMyAccount()->assertForbidden()->assertJsonPath('code', 'has_active_product');

    // Then no job was pushed and the account is intact
    Queue::assertNothingPushed();
    expect($user->fresh()->deleted_at)->toBeNull();
});

it('does not dispatch on the duplicate-request path', function () {
    // Given a member whose account a concurrent request already deleted (this model is stale)
    Queue::fake();
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);

    // When both requests run
    $first = app(DeleteAccount::class)->handle($user);
    $second = app(DeleteAccount::class)->handle($stale);

    // Then only the real deletion pushed a job
    expect($first)->toBeTrue()->and($second)->toBeFalse();
    Queue::assertPushed(VoidMemberVotes::class, 1);
});

it('dispatches after the deletion committed, outside its transaction', function () {
    // Given a recorder of the transaction depth and the account state when the job starts (sync queue)
    $user = User::factory()->create();
    $seen = [];
    Event::listen(JobProcessing::class, function () use (&$seen, $user) {
        $seen = [DB::transactionLevel(), DB::table('users')->where('id', $user->id)->value('deleted_at') !== null];
    });
    $level = DB::transactionLevel();

    // When the account is deleted
    app(DeleteAccount::class)->handle($user);

    // Then the job started at the caller's depth (DeleteAccount's own transaction was closed) and the row was already deleted
    expect($seen)->toBe([$level, true]);
});

it('voids the votes end to end on the sync queue and leaves frozen days alone', function () {
    // Given a member with votes on today's and tomorrow's launches and on yesterday's
    $user = votingMember();
    [$yesterday] = voteOnLaunch($user, -1);
    [$today, $todayVote] = voteOnLaunch($user, 0);
    [$tomorrow] = voteOnLaunch($user, 1);

    // When they delete their account
    removeMyAccount()->assertNoContent();

    // Then today's and tomorrow's votes are voided and uncounted, yesterday's stays
    expect($todayVote->fresh()->status)->toBe(VoteStatus::Voided)->and($todayVote->fresh()->voided_reason)->toBe('account_deleted');
    expect($today->fresh()->votes_count)->toBe(0)->and($tomorrow->fresh()->votes_count)->toBe(0)->and($yesterday->fresh()->votes_count)->toBe(1);
    expect(Vote::query()->active()->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps the deletion when the clean-up job fails, and reports the failure', function () {
    // Given a vote whose void fails with an audit error, on the sync queue
    Exceptions::fake();
    $user = votingMember();
    voteOnLaunch($user, 0);
    AuditLog::creating(fn () => throw new RuntimeException('audit down'));

    // When they delete their account
    removeMyAccount()->assertNoContent();

    // Then the account is deleted and the failure was reported, not turned into a 500
    expect($user->fresh()->deleted_at)->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
