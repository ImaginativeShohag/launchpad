<?php

use App\Actions\Admin\SuspendUser;
use App\Actions\AntiCheat\SuspendForAbuse;
use App\Actions\Auth\SignOutEverywhere;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Jobs\VoidMemberVotes;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => antiCheatFreeze());

afterEach(fn () => AuditLog::flushEventListeners());

/** POST the suspension. */
function antiCheatSuspend(string $memberId, array $body = ['reason' => 'Ring of fake accounts']): TestResponse
{
    return antiCheatPost("risk/members/{$memberId}/suspend", $body);
}

/** A member with two open signals of different kinds (half decayed: 20 and 14; confirmed: 40 and 28), a stored score and a vote today. */
function antiCheatSuspendSubject(): array
{
    $member = User::factory()->create();
    $one = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7);
    $two = antiCheatSignal($member, RiskSignalKind::DisposableEmail, 28, 7);
    $dismissed = antiCheatSignal($member, RiskSignalKind::LoginChurn, 20, 3, RiskOutcome::Dismissed);
    antiCheatSetScore($member, 34);
    $vote = antiCheatCountedVote($member, antiCheatLaunching(0));

    return [$member, [$one, $two], $dismissed, $vote];
}

it('suspends through the community action, confirms the open signals with one audit row each and recomputes the score', function () {
    // Given an admin and a member with two open signals and one dismissed signal
    $admin = signedInStaff(Role::Admin);
    [$member, $open, $dismissed] = antiCheatSuspendSubject();
    $reviewedAt = DB::table('risk_signals')->where('id', $dismissed->id)->value('reviewed_at');

    // When the admin suspends the member without asking for the vote clean-up
    $response = antiCheatSuspend($member->id, ['reason' => 'Ring of fake accounts', 'void_votes' => false])->assertOk();

    // Then the member is suspended with the reason and the response is their summary plus the meta
    $row = DB::table('users')->where('id', $member->id)->first();
    expect($row->suspended_at)->not->toBeNull()->and($row->suspended_reason)->toBe('Ring of fake accounts');
    expect($response->json('data.id'))->toBe($member->id)->and($response->json('data.suspended'))->toBeTrue()->and($response->json('meta'))->toBe(['suspended_now' => true, 'signals_confirmed' => 2, 'void_votes' => 'not_requested']);

    // And both open signals are confirmed by the admin with the note, the dismissed one is untouched, the score uses the full weights
    foreach ($open as $signal) {
        $stored = DB::table('risk_signals')->where('id', $signal->id)->first();
        expect($stored->outcome)->toBe('confirmed')->and($stored->reviewed_by)->toBe($admin->id)->and($stored->review_note)->toBe('suspended');
    }
    $after = DB::table('risk_signals')->where('id', $dismissed->id)->first();
    expect($after->outcome)->toBe('dismissed')->and($after->reviewed_at)->toBe($reviewedAt);
    expect(antiCheatScoreOf($member))->toBe(68);
    $audits = AuditLog::query()->where('action', 'risk_signal.reviewed')->get();
    expect($audits)->toHaveCount(2)->and($audits->every(fn ($a) => $a->actor_id === $admin->id && $a->meta['before'] === null && $a->meta['after'] === 'confirmed'))->toBeTrue();
    expect(AuditLog::query()->where('action', 'user.suspended')->count())->toBe(1);
});

it('calls the community SuspendUser action exactly once', function () {
    // Given a counting subclass bound in place of the action
    signedInStaff(Role::Admin);
    [$member] = antiCheatSuspendSubject();
    $calls = new ArrayObject(['n' => 0]);
    app()->bind(SuspendUser::class, fn () => new class($calls) extends SuspendUser
    {
        public function __construct(private readonly ArrayObject $calls)
        {
            parent::__construct(app(SignOutEverywhere::class));
        }

        public function handle(User $actor, User $target, string $reason): User
        {
            $this->calls['n'] = $this->calls['n'] + 1;

            return parent::handle($actor, $target, $reason);
        }
    });

    // When the admin suspends the member
    antiCheatSuspend($member->id)->assertOk();

    // Then the action ran once
    expect($calls['n'])->toBe(1)->and(DB::table('users')->where('id', $member->id)->value('suspended_at'))->not->toBeNull();
});

it('queues the void job with the reason suspended when asked, and the default is to ask', function () {
    // Given an admin, a suspended-to-be member and a fake queue
    Queue::fake();
    signedInStaff(Role::Admin);
    [$member] = antiCheatSuspendSubject();

    // When the admin suspends without the flag
    $response = antiCheatSuspend($member->id)->assertOk();

    // Then one job for this member with the reason suspended was queued
    expect($response->json('meta.void_votes'))->toBe('queued');
    Queue::assertPushed(VoidMemberVotes::class, 1);
    Queue::assertPushed(VoidMemberVotes::class, fn (VoidMemberVotes $job) => $job->userId === $member->id && $job->reason === 'suspended');
});

it('queues nothing when void_votes is false', function () {
    // Given a fake queue
    Queue::fake();
    signedInStaff(Role::Admin);
    [$member] = antiCheatSuspendSubject();

    // When the admin declines the clean-up
    antiCheatSuspend($member->id, ['reason' => 'Ring of fake accounts', 'void_votes' => false])->assertOk()->assertJsonPath('meta.void_votes', 'not_requested');

    // Then no job
    Queue::assertNothingPushed();
});

it('voids the votes end to end on the sync queue, after both commits', function () {
    // Given an admin, a member with a vote today and one yesterday (frozen), and a recorder of the depth when the job starts
    signedInStaff(Role::Admin);
    [$member, , , $vote] = antiCheatSuspendSubject();
    $frozen = antiCheatCountedVote($member, antiCheatLaunching(-1));
    $seen = [];
    Event::listen(JobProcessing::class, function () use (&$seen) {
        $seen[] = DB::transactionLevel();
    });
    $level = DB::transactionLevel();

    // When the admin suspends with the clean-up
    antiCheatSuspend($member->id)->assertOk()->assertJsonPath('meta.void_votes', 'queued');

    // Then today's vote is voided with the reason suspended, the frozen one is final, and the job ran outside every transaction
    expect(antiCheatVoteStatus($vote))->toBe('voided')->and(DB::table('votes')->where('id', $vote->id)->value('voided_reason'))->toBe('suspended')->and(antiCheatVoteStatus($frozen))->toBe('counted');
    expect($seen)->not->toBeEmpty()->and(collect($seen)->unique()->values()->all())->toBe([$level]);
});

it('runs no vote or product statement inside the suspension or the confirmation transaction', function () {
    // Given an admin, and a trace of every statement of the call with its depth
    $admin = User::factory()->role(Role::Admin)->create();
    [$member] = antiCheatSuspendSubject();
    $base = DB::transactionLevel();

    // When the action runs with the clean-up on the sync queue
    $trace = antiCheatTrace(fn () => app(SuspendForAbuse::class)->handle($admin, $member->id, 'Ring of fake accounts', true));

    // Then every statement on votes or products ran at the caller's depth plus the job's own transactions (never inside the other two)
    $firstVoteWork = collect($trace)->search(fn ($t) => str_contains($t['sql'], 'from "votes"') || str_contains($t['sql'], 'from "products"'));
    $lastConfirm = collect($trace)->keys()->filter(fn ($i) => str_contains($trace[$i]['sql'], 'update users set risk_score'))->last();
    $suspend = collect($trace)->search(fn ($t) => str_contains($t['sql'], 'update "users" set "suspended_at"'));
    expect($firstVoteWork)->not->toBeFalse()->and($lastConfirm)->not->toBeNull()->and($suspend)->not->toBeFalse();
    expect($suspend < $lastConfirm && $lastConfirm < $firstVoteWork)->toBeTrue();
});

it('is a repeatable repair: an already suspended member is a 200 no-op for the suspension, signals still confirmed, job still queued', function () {
    // Given a member suspended earlier without the clean-up, who has since collected a new open signal
    Queue::fake();
    $admin = signedInStaff(Role::Admin);
    [$member] = antiCheatSuspendSubject();
    antiCheatSuspend($member->id, ['reason' => 'First reason here', 'void_votes' => false])->assertOk();
    $newSignal = antiCheatSignal($member, RiskSignalKind::VoteBurst, 25, 0);

    // When the admin suspends again, now asking for the clean-up
    $response = antiCheatSuspend($member->id, ['reason' => 'Second reason here'])->assertOk();

    // Then the suspension did nothing new, the first reason stays, the new signal is confirmed and the job is queued
    expect($response->json('meta'))->toBe(['suspended_now' => false, 'signals_confirmed' => 1, 'void_votes' => 'queued']);
    expect(DB::table('users')->where('id', $member->id)->value('suspended_reason'))->toBe('First reason here')->and(DB::table('risk_signals')->where('id', $newSignal->id)->value('outcome'))->toBe('confirmed');
    expect(AuditLog::query()->where('action', 'user.suspended')->count())->toBe(1);
    Queue::assertPushed(VoidMemberVotes::class, 1);
    unset($admin);
});

it('refuses an admin or oneself with 403 cannot_suspend and does nothing else', function (string $who) {
    // Given an admin with an open signal about themselves and another admin with one
    Queue::fake();
    $admin = signedInStaff(Role::Admin);
    $target = $who === 'self' ? $admin : User::factory()->role(Role::Admin)->create();
    $signal = antiCheatSignal($target, RiskSignalKind::SharedDevice, 40, 1);

    // When the admin tries to suspend the target
    // Then 403 cannot_suspend, no signal confirmed, no job, nobody suspended
    antiCheatSuspend($target->id)->assertForbidden()->assertJsonPath('code', 'cannot_suspend');
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull()->and(DB::table('users')->where('id', $target->id)->value('suspended_at'))->toBeNull();
    Queue::assertNothingPushed();
})->with(['self', 'another admin']);

it('answers an admin 404 for an unknown, malformed or deleted member', function () {
    // Given an admin and a deleted member with an open signal
    signedInStaff(Role::Admin);
    $deleted = User::factory()->create(['deleted_at' => now()]);
    $signal = antiCheatSignal($deleted, RiskSignalKind::SharedDevice, 40, 1);

    // When they suspend ids that name no live member
    // Then 404 each, and the deleted member's signal is untouched
    foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid', 'ab%00cd', $deleted->id] as $id) {
        antiCheatSuspend($id)->assertNotFound();
    }
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull();
});

it('refuses a reviewer and a member with the same 403 for any id and any body, and a guest with 401', function (?Role $role) {
    // Given a member with an open signal, and a reviewer, a member or a guest
    [$member, $open] = antiCheatSuspendSubject();
    if ($role === Role::Member) {
        signedInMember();
    } elseif ($role !== null) {
        signedInStaff($role);
    }

    // When they post to an existing id, an unknown id and a malformed id, with a good and with garbage bodies
    $responses = [];
    foreach ([$member->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid'] as $id) {
        foreach ([['reason' => 'A fine reason'], ['reason' => ['x'], 'void_votes' => 'yes'], [], ['reason' => 'A fine reason', 'void_votes' => null]] as $body) {
            $responses[] = antiCheatSuspend($id, $body);
        }
    }

    // Then every answer is the same 403 (401 for a guest) and the member is untouched
    foreach ($responses as $response) {
        $response->assertStatus($role === null ? 401 : 403);
    }
    expect(collect($responses)->map->getContent()->unique())->toHaveCount(1)->and(DB::table('users')->where('id', $member->id)->value('suspended_at'))->toBeNull();
    expect(DB::table('risk_signals')->where('id', $open[0]->id)->value('reviewed_at'))->toBeNull();
})->with([[null], [Role::Reviewer], [Role::Member]]);

it('refuses a stale actor before looking anything up', function (Closure $stale) {
    // Given an actor model that is an admin in memory while the row changed
    $actor = User::factory()->role(Role::Admin)->create();
    [$member] = antiCheatSuspendSubject();
    $stale($actor);

    // When the action runs
    // Then it refuses and the member is not suspended
    expect(fn () => app(SuspendForAbuse::class)->handle($actor, $member->id, 'Ring of fake accounts', true))->toThrow(AuthorizationException::class);
    expect(DB::table('users')->where('id', $member->id)->value('suspended_at'))->toBeNull();
})->with([
    'demoted' => [fn (User $u) => DB::table('users')->where('id', $u->id)->update(['role' => 'reviewer'])],
    'suspended' => [fn (User $u) => DB::table('users')->where('id', $u->id)->update(['suspended_at' => now()])],
]);

it('rejects a bad body with 422 and suspends nobody', function (array $body) {
    // Given an admin and a member
    signedInStaff(Role::Admin);
    [$member] = antiCheatSuspendSubject();

    // When the body is wrong
    // Then 422 and the member is not suspended
    antiCheatSuspend($member->id, $body)->assertUnprocessable();
    expect(DB::table('users')->where('id', $member->id)->value('suspended_at'))->toBeNull();
})->with([
    'no reason' => [[]], 'empty' => [['reason' => '']], '4 chars' => [['reason' => 'abcd']], '501 chars' => [['reason' => str_repeat('a', 501)]],
    'reason array' => [['reason' => ['abcde']]], 'reason object' => [['reason' => ['a' => 'abcde']]], 'reason null' => [['reason' => null]], 'reason number' => [['reason' => 12345]], 'reason bool' => [['reason' => true]],
    'void_votes string true' => [['reason' => 'Ring of fakes', 'void_votes' => 'true']],
    'void_votes 1' => [['reason' => 'Ring of fakes', 'void_votes' => 1]],
    'void_votes "1"' => [['reason' => 'Ring of fakes', 'void_votes' => '1']],
    'void_votes 0' => [['reason' => 'Ring of fakes', 'void_votes' => 0]],
    'void_votes null' => [['reason' => 'Ring of fakes', 'void_votes' => null]],
    'void_votes empty' => [['reason' => 'Ring of fakes', 'void_votes' => '']],
    'void_votes array' => [['reason' => 'Ring of fakes', 'void_votes' => [true]]],
    'void_votes object' => [['reason' => 'Ring of fakes', 'void_votes' => ['a' => true]]],
]);

it('rejects a JSON body that is not an object, invalid UTF-8, and cleans control characters from the reason', function () {
    // Given an admin and two members
    signedInStaff(Role::Admin);
    [$member] = antiCheatSuspendSubject();
    [$other] = antiCheatSuspendSubject();

    // When non-object JSON arrives, and a form carries malformed bytes
    foreach (['[]', 'null', '"abcde"', '5', 'true'] as $raw) {
        $this->call('POST', "/api/v1/staff/risk/members/{$member->id}/suspend", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertUnprocessable();
    }
    $this->post("/api/v1/staff/risk/members/{$member->id}/suspend", ['reason' => "bad \xC3\x28 bytes"], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect(DB::table('users')->where('id', $member->id)->value('suspended_at'))->toBeNull();

    // Then a reason with control characters is stored cleaned, and extra fields are ignored
    antiCheatSuspend($other->id, ['reason' => "  tidy\x00 reason\x07  ", 'role' => 'admin', 'void_votes' => false])->assertOk();
    expect(DB::table('users')->where('id', $other->id)->value('suspended_reason'))->toBe('tidy reason')->and(DB::table('users')->where('id', $other->id)->value('role'))->toBe('member');
});

it('reports a lost dispatch, keeps the suspension and repairs it on the next call', function () {
    // Given a queue that refuses jobs
    Exceptions::fake();
    $admin = signedInStaff(Role::Admin);
    [$member, , , $vote] = antiCheatSuspendSubject();
    $broken = antiCheatFailingQueue();

    // When the admin suspends with the clean-up
    $response = antiCheatSuspend($member->id)->assertOk();

    // Then the response says so, the failure was reported, the member is suspended and the vote still counts
    expect($response->json('meta.void_votes'))->toBe('dispatch_failed');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'queue down');
    expect(DB::table('users')->where('id', $member->id)->value('suspended_at'))->not->toBeNull()->and(antiCheatVoteStatus($vote))->toBe('counted');

    // And calling again with a working queue finishes the job (the failed dispatch must not leave the unique lock behind)
    $broken['down'] = false;
    antiCheatSuspend($member->id)->assertOk()->assertJsonPath('meta.void_votes', 'queued')->assertJsonPath('meta.suspended_now', false);
    expect(antiCheatVoteStatus($vote))->toBe('voided');
    unset($admin);
});

it('keeps the member suspended when the confirmation fails and finishes it on the next call', function () {
    // Given an audit insert that fails for the review rows
    Exceptions::fake();
    $admin = User::factory()->role(Role::Admin)->create();
    [$member, $open] = antiCheatSuspendSubject();
    AuditLog::creating(function (AuditLog $log) {
        if ($log->action === 'risk_signal.reviewed') {
            DB::statement('select 1/0');
        }
    });

    // When the action runs
    // Then it throws, the suspension is committed, the signals are still open and the score unchanged
    expect(fn () => app(SuspendForAbuse::class)->handle($admin, $member->id, 'Ring of fake accounts', false))->toThrow(QueryException::class);
    expect(DB::table('users')->where('id', $member->id)->value('suspended_at'))->not->toBeNull()->and(DB::table('risk_signals')->where('id', $open[0]->id)->value('reviewed_at'))->toBeNull()->and(antiCheatScoreOf($member))->toBe(34);

    // And once the fault is gone the next call confirms them
    AuditLog::flushEventListeners();
    $result = app(SuspendForAbuse::class)->handle($admin, $member->id, 'Ring of fake accounts', false);
    expect($result)->toBe(['member' => $member->id, 'suspended_now' => false, 'signals_confirmed' => 2, 'void_votes' => 'not_requested']);
    expect(antiCheatScoreOf($member))->toBe(68);
});

it('takes the open signal rows in id order, then the user row, in the confirmation transaction', function () {
    // Given an admin and a member with two open signals
    $admin = User::factory()->role(Role::Admin)->create();
    [$member] = antiCheatSuspendSubject();

    // When the action runs (no clean-up)
    $trace = antiCheatTrace(fn () => app(SuspendForAbuse::class)->handle($admin, $member->id, 'Ring of fake accounts', false));

    // Then the signal rows are locked FOR UPDATE ordered by id, then the user row FOR NO KEY UPDATE, then the score is read and written
    $find = fn (string $needle) => collect($trace)->search(fn ($t) => str_contains($t['sql'], $needle));
    $signals = $find('from "risk_signals" where "user_id" = ?');
    $user = $find('for no key update');
    $score = $find('from risk_signals where user_id in');
    expect($trace[$signals]['sql'])->toContain('order by "id" asc')->toContain('for update');
    expect($signals < $user && $user < $score)->toBeTrue();
    expect(collect($trace)->filter(fn ($t) => str_contains($t['sql'], 'products')))->toBeEmpty();
});
