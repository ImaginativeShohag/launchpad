<?php

use App\Actions\Auth\DeleteAccount;
use App\Actions\Vote\HourlyBuckets;
use App\Actions\Vote\VoidVote;
use App\Enums\VoteStatus;
use App\Exceptions\ProductRuleViolation;
use App\Jobs\VoidMemberVotes;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC')));
afterEach(fn () => AuditLog::flushEventListeners());

/** A live product launching `$offset` days from today. */
function voidMemberProductLaunching(int $offset = 0): Product
{
    return Product::factory()->live(now('UTC')->addDays($offset)->toDateString())->create();
}

/** An active vote with its counter and bucket, written directly (a vote on a future or past day cannot be cast through the API). */
function voidMemberActiveVoteOn(User $voter, Product $product): Vote
{
    $vote = Vote::factory()->create(['user_id' => $voter->id, 'product_id' => $product->id, 'voted_at' => now()->startOfSecond()]);
    DB::table('products')->where('id', $product->id)->increment('votes_count');
    HourlyBuckets::add($product->id, $vote->voted_at, 1);

    return $vote;
}

function voidMemberDeleted(): User
{
    return User::factory()->create(['deleted_at' => now()]);
}

it('declares a unique, retried queued job keyed on the reason and the user id', function () {
    // Given the job for a user, with and without a reason
    $job = new VoidMemberVotes('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $suspended = new VoidMemberVotes('01ARZ3NDEKTSV4RRFFQ69G5FAV', VoidMemberVotes::REASON_SUSPENDED);

    // When its contract is inspected
    // Then it is queued, unique per reason and user, with three tries, the two backoffs and a lock life above the timeout
    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->reason)->toBe('account_deleted')->and($job->uniqueId())->toBe('account_deleted:01ARZ3NDEKTSV4RRFFQ69G5FAV')->and($suspended->uniqueId())->toBe('suspended:01ARZ3NDEKTSV4RRFFQ69G5FAV');
    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 60])->and($job->uniqueFor)->toBe(3600)->and($job->timeout)->toBe(60)->and($job->timeout)->toBeLessThan(90);
});

it('keeps the reason a writable property with a default, so a payload queued before the rename still loads', function () {
    // Given the reflection of the reason property
    $property = new ReflectionProperty(VoidMemberVotes::class, 'reason');

    // When it is inspected
    // Then it is neither readonly nor promoted, and has the legacy default
    expect($property->isReadOnly())->toBeFalse()->and($property->isPromoted())->toBeFalse()->and($property->hasDefaultValue())->toBeTrue()->and($property->getDefaultValue())->toBe('account_deleted');
});

it('voids a suspended member votes on unfrozen days with the reason suspended, and leaves frozen days alone', function () {
    // Given a suspended member with votes on yesterday's, today's and tomorrow's launches
    $member = User::factory()->create(['suspended_at' => now(), 'suspended_reason' => 'Ring']);
    $votes = collect([-1, 0, 1])->map(fn (int $offset) => voidMemberActiveVoteOn($member, voidMemberProductLaunching($offset)));

    // When the suspended job runs
    (new VoidMemberVotes($member->id, VoidMemberVotes::REASON_SUSPENDED))->handle(app(VoidVote::class));

    // Then today's and tomorrow's are voided with the reason, yesterday's is final
    expect($votes[0]->fresh()->status)->toBe(VoteStatus::Counted);
    foreach ([1, 2] as $i) {
        expect($votes[$i]->fresh()->status)->toBe(VoteStatus::Voided)->and($votes[$i]->fresh()->voided_reason)->toBe('suspended');
    }
});

it('does nothing for a member who is not in the state the reason names', function (string $reason, array $state) {
    // Given a member in a state that does not match the reason (a restored member, a deleted member sent as suspended, a live one)
    $member = User::factory()->create($state);
    $product = voidMemberProductLaunching();
    $vote = voidMemberActiveVoteOn($member, $product);

    // When the job runs with that reason
    (new VoidMemberVotes($member->id, $reason))->handle(app(VoidVote::class));

    // Then the vote still counts
    expect($vote->fresh()->status)->toBe(VoteStatus::Counted)->and($product->fresh()->votes_count)->toBe(1);
})->with([
    'suspended reason, restored member' => ['suspended', []],
    'suspended reason, only deleted' => ['suspended', ['deleted_at' => '2026-10-19 09:00:00']],
    'deleted reason, only suspended' => ['account_deleted', ['suspended_at' => '2026-10-19 09:00:00', 'suspended_reason' => 'x']],
    'unknown reason, deleted member' => ['whatever', ['deleted_at' => '2026-10-19 09:00:00']],
]);

it('guards a deleted-and-suspended member by the reason it was sent with', function (string $reason) {
    // Given a member who is both deleted and suspended
    $member = User::factory()->create(['deleted_at' => now(), 'suspended_at' => now(), 'suspended_reason' => 'x']);
    $vote = voidMemberActiveVoteOn($member, voidMemberProductLaunching());

    // When the job runs with either reason
    (new VoidMemberVotes($member->id, $reason))->handle(app(VoidVote::class));

    // Then it voids with that reason
    expect($vote->fresh()->status)->toBe(VoteStatus::Voided)->and($vote->fresh()->voided_reason)->toBe($reason);
})->with(['account_deleted', 'suspended']);

it('voids a deleted member votes on today and future launches and leaves frozen days alone', function () {
    // Given a deleted member with votes on yesterday's, today's and tomorrow's launches
    $member = voidMemberDeleted();
    $yesterday = voidMemberProductLaunching(-1);
    $today = voidMemberProductLaunching(0);
    $tomorrow = voidMemberProductLaunching(1);
    $votes = collect([$yesterday, $today, $tomorrow])->map(fn (Product $p) => voidMemberActiveVoteOn($member, $p));

    // When the job runs
    (new VoidMemberVotes($member->id))->handle(app(VoidVote::class));

    // Then today's and tomorrow's are voided with the reason, yesterday's is untouched
    expect($votes[0]->fresh()->status)->toBe(VoteStatus::Counted);
    foreach ([1, 2] as $i) {
        expect($votes[$i]->fresh()->status)->toBe(VoteStatus::Voided)->and($votes[$i]->fresh()->voided_reason)->toBe('account_deleted');
    }
    expect($yesterday->fresh()->votes_count)->toBe(1)->and($today->fresh()->votes_count)->toBe(0)->and($tomorrow->fresh()->votes_count)->toBe(0);
    expect(AuditLog::query()->where('action', 'vote.voided')->count())->toBe(2);
});

it('keeps the counts equal to the remaining active votes and does not touch other members', function () {
    // Given a deleted member and another member voting on the same product, and a withdrawn vote of the deleted member
    $member = voidMemberDeleted();
    $other = User::factory()->create();
    $product = voidMemberProductLaunching();
    $second = voidMemberProductLaunching();
    voidMemberActiveVoteOn($member, $product);
    voidMemberActiveVoteOn($other, $product);
    voidMemberActiveVoteOn($member, $second);
    $withdrawn = Vote::factory()->removed()->create(['user_id' => $member->id, 'product_id' => voidMemberProductLaunching()->id]);

    // When the job runs
    (new VoidMemberVotes($member->id))->handle(app(VoidVote::class));

    // Then each count equals its active votes and the other member's vote is still counted
    foreach ([$product, $second] as $p) {
        expect($p->fresh()->votes_count)->toBe(Vote::query()->active()->where('product_id', $p->id)->count());
    }
    expect($product->fresh()->votes_count)->toBe(1)->and(Vote::query()->active()->where('user_id', $other->id)->count())->toBe(1);
    expect($withdrawn->fresh()->status)->toBe(VoteStatus::Counted);
    expect(DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe(1);
});

it('is idempotent: a second run changes nothing', function () {
    // Given a member whose votes were voided by a first run
    $member = voidMemberDeleted();
    $product = voidMemberProductLaunching();
    voidMemberActiveVoteOn($member, $product);
    voidMemberActiveVoteOn(User::factory()->create(), $product);
    (new VoidMemberVotes($member->id))->handle(app(VoidVote::class));
    $state = fn () => [Vote::query()->orderBy('id')->get()->map->only(['status', 'voided_at'])->all(), $product->fresh()->votes_count, AuditLog::query()->count()];
    $before = $state();

    // When it runs again
    (new VoidMemberVotes($member->id))->handle(app(VoidVote::class));

    // Then nothing moved
    expect($state())->toEqual($before)->and($product->fresh()->votes_count)->toBe(1);
});

it('works through more than one chunk', function () {
    // Given five votes and a chunk size of two
    $member = voidMemberDeleted();
    $votes = collect(range(1, 5))->map(fn () => voidMemberActiveVoteOn($member, voidMemberProductLaunching()));

    // When the job runs
    (new VoidMemberVotes($member->id))->handle(app(VoidVote::class), 2);

    // Then all five are voided
    expect(Vote::query()->where('status', 'voided')->count())->toBe(5)->and(Vote::query()->active()->count())->toBe(0);
});

it('ignores an account that is not deleted', function () {
    // Given a live member with an active vote (a job dispatched by mistake)
    $member = User::factory()->create();
    $product = voidMemberProductLaunching();
    $vote = voidMemberActiveVoteOn($member, $product);

    // When the job runs for that member
    (new VoidMemberVotes($member->id))->handle(app(VoidVote::class));

    // Then nothing is voided
    expect($vote->fresh()->status)->toBe(VoteStatus::Counted)->and($product->fresh()->votes_count)->toBe(1);
});

it('keeps going when one vote fails, even on a SQL error, then rethrows so the queue retries the rest', function () {
    // Given three votes and an audit insert that fails with a SQL error for the middle one (id order)
    Exceptions::fake();
    $member = voidMemberDeleted();
    $votes = collect(range(1, 3))->map(fn () => voidMemberActiveVoteOn($member, voidMemberProductLaunching()))->sortBy('id')->values();
    $bad = $votes[1];
    AuditLog::creating(function (AuditLog $log) use ($bad) {
        if ($log->subject_id === $bad->id) {
            DB::statement('select 1/0');
        }
    });

    // When the job runs
    $run = fn () => (new VoidMemberVotes($member->id))->handle(app(VoidVote::class));

    // Then it throws at the end, the other two are voided, the bad one is intact, and the failure was reported
    expect($run)->toThrow(QueryException::class);
    expect($votes[0]->fresh()->status)->toBe(VoteStatus::Voided)->and($votes[2]->fresh()->status)->toBe(VoteStatus::Voided)->and($bad->fresh()->status)->toBe(VoteStatus::Counted);
    Exceptions::assertReported(QueryException::class);

    // And once the fault is gone a retry finishes the remainder
    AuditLog::flushEventListeners();
    $run();
    expect($bad->fresh()->status)->toBe(VoteStatus::Voided);
});

it('skips a vote whose day froze meanwhile and still voids the rest, without failing', function () {
    // Given two votes and a voider that reports a frozen day for the first one
    $member = voidMemberDeleted();
    $votes = collect(range(1, 2))->map(fn () => voidMemberActiveVoteOn($member, voidMemberProductLaunching()))->sortBy('id')->values();
    $frozen = $votes[0]->id;
    $voider = new class($frozen) extends VoidVote
    {
        public function __construct(private readonly string $frozenId) {}

        public function handle(string $voteId, string $reason, ?User $actor = null): bool
        {
            return $voteId === $this->frozenId ? throw ProductRuleViolation::dayFrozen() : parent::handle($voteId, $reason, $actor);
        }
    };

    // When the job runs
    (new VoidMemberVotes($member->id))->handle($voider);

    // Then it does not throw, the frozen one is skipped and the other is voided
    expect($votes[0]->fresh()->status)->toBe(VoteStatus::Counted)->and($votes[1]->fresh()->status)->toBe(VoteStatus::Voided);
});

it('terminates and finishes the others when one vote keeps failing across several chunks', function () {
    // Given five votes in chunks of two and an audit insert that always fails for the first two (a whole chunk)
    Exceptions::fake();
    $member = voidMemberDeleted();
    $votes = collect(range(1, 5))->map(fn () => voidMemberActiveVoteOn($member, voidMemberProductLaunching()))->sortBy('id')->values();
    AuditLog::creating(function (AuditLog $log) use ($votes) {
        if (in_array($log->subject_id, [$votes[0]->id, $votes[1]->id], true)) {
            DB::statement('select 1/0');
        }
    });

    // When the job runs with a chunk size of 2
    $run = fn () => (new VoidMemberVotes($member->id))->handle(app(VoidVote::class), 2);

    // Then it ends (the cursor moves past the failing chunk; without it the loop never ends), the other three are voided, and it throws
    expect($run)->toThrow(QueryException::class);
    expect(Vote::query()->where('status', 'voided')->count())->toBe(3)->and($votes[0]->fresh()->status)->toBe(VoteStatus::Counted);
});

it('is re-dispatched by the hourly sweep for deleted members that still hold counted votes on unfrozen days', function () {
    // Given a deleted member with an active vote today, one with only a frozen-day vote, and a live member with a vote
    Queue::fake();
    $stuck = voidMemberDeleted();
    voidMemberActiveVoteOn($stuck, voidMemberProductLaunching());
    $frozenOnly = voidMemberDeleted();
    voidMemberActiveVoteOn($frozenOnly, voidMemberProductLaunching(-1));
    voidMemberActiveVoteOn(User::factory()->create(), voidMemberProductLaunching());
    $done = voidMemberDeleted();
    Vote::factory()->voided()->create(['user_id' => $done->id, 'product_id' => voidMemberProductLaunching()->id]);

    // When the sweep runs
    $this->artisan('launchpad:sweep-deleted-member-votes')->expectsOutput('Dispatched 1 clean-up job(s).')->assertSuccessful();

    // Then only the stuck member's job is queued
    Queue::assertPushed(VoidMemberVotes::class, 1);
    Queue::assertPushed(VoidMemberVotes::class, fn (VoidMemberVotes $job) => $job->userId === $stuck->id && $job->reason === 'account_deleted');
});

it('does not sweep a suspended member: their votes keep counting unless staff asked for the void', function () {
    // Given a suspended member with an active vote today
    Queue::fake();
    $member = User::factory()->create(['suspended_at' => now(), 'suspended_reason' => 'Ring']);
    voidMemberActiveVoteOn($member, voidMemberProductLaunching());

    // When the sweep runs
    // Then nothing is dispatched
    $this->artisan('launchpad:sweep-deleted-member-votes')->expectsOutput('Dispatched 0 clean-up job(s).')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('exits non-zero when a sweep dispatch fails and registers the sweep hourly', function () {
    // Given a stuck member and a queue that throws on dispatch
    $member = voidMemberDeleted();
    voidMemberActiveVoteOn($member, voidMemberProductLaunching());
    Exceptions::fake();
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue down'));

    // When the sweep runs
    $this->artisan('launchpad:sweep-deleted-member-votes')->assertFailed();

    // Then the failure was reported, and the command is scheduled hourly, without overlap, on one server
    Exceptions::assertReportedCount(1);
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'launchpad:sweep-deleted-member-votes'));
    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 * * * *')->and($events->first()->withoutOverlapping)->toBeTrue()->and($events->first()->onOneServer)->toBeTrue();
});

it('releases the unique lock when the sweep cannot dispatch, so the next sweep really queues the job', function () {
    // Given a stuck deleted member and a queue that is down
    Exceptions::fake();
    $member = voidMemberDeleted();
    $vote = voidMemberActiveVoteOn($member, voidMemberProductLaunching());
    $broken = antiCheatFailingQueue();

    // When the sweep runs, fails, and runs again once the queue is back
    $this->artisan('launchpad:sweep-deleted-member-votes')->assertFailed();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'queue down');
    $broken['down'] = false;
    $this->artisan('launchpad:sweep-deleted-member-votes')->assertSuccessful();

    // Then the repair really ran the job
    expect($vote->fresh()->status)->toBe(VoteStatus::Voided);
});

it('releases the unique lock when the deletion cannot dispatch, so the sweep can repair it at once', function () {
    // Given a member with a vote today and a queue that is down
    Exceptions::fake();
    $user = User::factory()->create();
    $vote = voidMemberActiveVoteOn($user, voidMemberProductLaunching());
    $broken = antiCheatFailingQueue();

    // When the account is deleted, then the queue recovers and the sweep runs
    expect(app(DeleteAccount::class)->handle($user))->toBeTrue();
    expect($vote->fresh()->status)->toBe(VoteStatus::Counted);
    $broken['down'] = false;
    $this->artisan('launchpad:sweep-deleted-member-votes')->assertSuccessful();

    // Then the votes were voided (a leaked lock would have made the sweep a no-op)
    expect($vote->fresh()->status)->toBe(VoteStatus::Voided);
});
