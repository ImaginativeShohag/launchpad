<?php

use App\Actions\AntiCheat\DetectAbuse;
use App\Actions\AntiCheat\ScoreCalculator;
use App\Actions\AntiCheat\ScoreUsers;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\RiskSignal;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

function antiCheatScoringOld(): array
{
    return ['created_at' => antiCheatNow()->subDays(10)];
}

it('writes the expected score for a ring member and leaves a quiet member at zero', function () {
    // Given a ring of four (shared_device 40) and a lone member with one vote
    antiCheatFreeze();
    $ring = antiCheatRing(antiCheatLiveProduct(), 4, userAttributes: antiCheatScoringOld());
    $quiet = User::factory()->create(antiCheatScoringOld());
    antiCheatVote($quiet, antiCheatLiveProduct());

    // When the job runs
    $result = antiCheatDetect();

    // Then each ring member carries 40, the quiet member 0, and four rows were rescored
    expect($result->usersRescored)->toBe(4);
    foreach ($ring as $member) {
        expect(antiCheatScoreOf($member))->toBe(40);
    }
    expect(antiCheatScoreOf($quiet))->toBe(0);
});

it('changes nothing on a rerun at the same state and leaves updated_at alone', function () {
    // Given a scored ring
    antiCheatFreeze();
    $ring = antiCheatRing(antiCheatLiveProduct(), 3, userAttributes: antiCheatScoringOld());
    antiCheatDetect();
    $ids = array_map(fn ($u) => $u->id, $ring);
    $snapshot = fn () => DB::table('users')->whereIn('id', $ids)->orderBy('id')->get(['id', 'risk_score', 'updated_at'])->all();
    $before = $snapshot();

    // When the job runs again at the same instant
    $result = antiCheatDetect();

    // Then no score is rewritten and no row changed
    expect($result->usersRescored)->toBe(0)->and($snapshot())->toEqual($before);
});

it('lowers a score by decay with no new signal, reaches zero after the window and then stops rewriting it', function () {
    // Given a ring member scored 40 at the fixed instant
    antiCheatFreeze();
    [$member] = antiCheatRing(antiCheatLiveProduct(), 3, userAttributes: antiCheatScoringOld());
    antiCheatDetect();
    expect(antiCheatScoreOf($member))->toBe(40);

    // When the job runs seven days later (the vote's day is long past the scan set)
    app(DetectAbuse::class)->handle(antiCheatNow()->addDays(7));

    // Then the score halved although no member was named by a finding
    expect(antiCheatScoreOf($member))->toBe(20);

    // And after the 14 days it is zero, the next run rewrites nothing
    $result = app(DetectAbuse::class)->handle(antiCheatNow()->addDays(14));
    expect(antiCheatScoreOf($member))->toBe(0)->and($result->usersRescored)->toBe(3);
    expect(app(DetectAbuse::class)->handle(antiCheatNow()->addDays(15))->usersRescored)->toBe(0);
});

it('keeps a confirmed signal at full weight and lets a dismissed one lower the score at the next run', function () {
    // Given a member with two old signals of different kinds (both long past the decay window if open)
    antiCheatFreeze();
    $member = User::factory()->create(antiCheatScoringOld());
    $confirmed = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 100, RiskOutcome::Confirmed);
    antiCheatSignal($member, RiskSignalKind::DisposableEmail, 25, 0);
    antiCheatSetScore($member, 65);

    // When the job runs
    antiCheatDetect();

    // Then the confirmed one still counts in full and the open one is the second contribution
    expect(antiCheatScoreOf($member))->toBe(65);

    // And when staff dismiss the confirmed one (directly) the next run drops it
    RiskSignal::query()->whereKey($confirmed->id)->update(['outcome' => RiskOutcome::Dismissed->value]);
    antiCheatDetect();
    expect(antiCheatScoreOf($member))->toBe(25);
});

it('skips deleted members and scores suspended ones', function () {
    // Given a deleted and a suspended member, both with a fresh signal and a stale stored score
    antiCheatFreeze();
    $deleted = User::factory()->create([...antiCheatScoringOld(), 'deleted_at' => antiCheatNow()->subDay()]);
    $suspended = User::factory()->create([...antiCheatScoringOld(), 'suspended_at' => antiCheatNow()->subDay()]);
    foreach ([$deleted, $suspended] as $member) {
        antiCheatSignal($member, RiskSignalKind::SharedDevice, 40);
    }
    antiCheatSetScore($deleted, 33);
    antiCheatSetScore($suspended, 33);

    // When the scoring runs for both explicitly and through the job
    $changed = app(ScoreUsers::class)->handle([$deleted->id, $suspended->id], antiCheatNow());
    antiCheatDetect();

    // Then only the suspended member moved
    expect($changed)->toBe(1)->and(antiCheatScoreOf($deleted))->toBe(33)->and(antiCheatScoreOf($suspended))->toBe(40);
});

it('gives the same scores with one-member chunks and never exceeds 100', function () {
    // Given a member with signals worth 140 in three kinds, and another worth 40 (both carry a stale score, so the job revisits them)
    antiCheatFreeze();
    $big = User::factory()->create(antiCheatScoringOld());
    antiCheatSignal($big, RiskSignalKind::SharedDevice, 40);
    antiCheatSignal($big, RiskSignalKind::CoordinatedVotes, 70);
    antiCheatSignal($big, RiskSignalKind::VoteBurst, 30);
    $small = User::factory()->create(antiCheatScoringOld());
    antiCheatSignal($small, RiskSignalKind::SharedDevice, 40);
    antiCheatSetScore($big, 1);
    antiCheatSetScore($small, 1);

    // When the job runs with chunks of one
    antiCheatDetect(['userChunk' => 1]);

    // Then the sum is capped at 100 and the other member has 40
    expect(antiCheatScoreOf($big))->toBe(100)->and(antiCheatScoreOf($small))->toBe(40);
});

it('counts a failing scoring chunk, reports it and still updates the others', function () {
    // Given two members with signals and a scoring action whose second chunk throws
    antiCheatFreeze();
    Exceptions::fake();
    [$first, $second] = [User::factory()->create(antiCheatScoringOld()), User::factory()->create(antiCheatScoringOld())];
    foreach ([$first, $second] as $member) {
        antiCheatSignal($member, RiskSignalKind::SharedDevice, 40);
        antiCheatSetScore($member, 1);
    }
    $bad = max($first->id, $second->id);
    $good = min($first->id, $second->id);
    app()->bind(ScoreUsers::class, fn () => new class(app(Settings::class), new ScoreCalculator, $bad) extends ScoreUsers
    {
        public function __construct(Settings $settings, ScoreCalculator $calculator, private readonly string $bad)
        {
            parent::__construct($settings, $calculator);
        }

        public function handle(array $userIds, CarbonImmutable $now, ?int $decayDays = null): int
        {
            if (in_array($this->bad, $userIds, true)) {
                throw new RuntimeException('boom');
            }

            return parent::handle($userIds, $now, $decayDays);
        }
    });

    // When the job runs with one member per chunk
    $result = antiCheatDetect(['userChunk' => 1]);

    // Then one chunk failed and was reported, the other member was rescored, and the connection still works
    expect($result->failedChunks)->toBe(1)->and($result->usersRescored)->toBe(1);
    Exceptions::assertReportedCount(1);
    expect(antiCheatScoreOf($good))->toBe(40)->and(antiCheatScoreOf($bad))->toBe(1);
});

it('locks only user rows with FOR NO KEY UPDATE SKIP LOCKED in id order and never touches products', function () {
    // Given a member with a stale score
    antiCheatFreeze();
    $member = antiCheatSetScore(User::factory()->create(antiCheatScoringOld()), 5);
    antiCheatSignal($member, RiskSignalKind::SharedDevice, 40);

    // When the scoring runs and its statements are recorded
    $queries = antiCheatQueries(fn () => app(ScoreUsers::class)->handle([$member->id], antiCheatNow()));

    // Then the lock statement is the skip-locked one in id order, nothing locks products, and no plain FOR UPDATE exists
    $locks = array_values(array_filter($queries, fn (string $q) => str_contains($q, 'for ')));
    expect($locks)->toHaveCount(1);
    expect($locks[0])->toContain('from users')->toContain('order by id for no key update skip locked');
    expect(implode("\n", $queries))->not->toContain('products')->not->toContain('for update');
});

it('rescores one member whose row the caller holds locked: returns the score, writes only a change, reads decay and outcomes', function () {
    // Given a member with a half-decayed open signal (20) and a confirmed one of another kind (28), stored score stale at 5
    antiCheatFreeze();
    $member = User::factory()->create();
    antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7);
    antiCheatSignal($member, RiskSignalKind::DisposableEmail, 28, 30, RiskOutcome::Confirmed);
    antiCheatSetScore($member, 5);

    // When it is rescored inside the caller's transaction with the row locked
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (str_contains(strtolower($q->sql), 'update users')) {
            $writes[] = $q->sql;
        }
    });
    $score = DB::transaction(function () use ($member) {
        DB::select('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE', [$member->id]);

        return app(ScoreUsers::class)->forLockedUser($member->id, antiCheatNow());
    });

    // Then 20 + 28 is stored and returned, and a second call with nothing to change issues the statement but changes no row
    expect($score)->toBe(48)->and(antiCheatScoreOf($member))->toBe(48)->and($writes)->toHaveCount(1);
    $before = DB::table('users')->where('id', $member->id)->value('updated_at');
    expect(app(ScoreUsers::class)->forLockedUser($member->id, antiCheatNow()))->toBe(48);
    expect(DB::table('users')->where('id', $member->id)->value('updated_at'))->toBe($before);

    // And a member without signals scores 0, and the decay window is honoured when passed in
    $quiet = antiCheatSetScore(User::factory()->create(), 9);
    expect(app(ScoreUsers::class)->forLockedUser($quiet->id, antiCheatNow()))->toBe(0)->and(antiCheatScoreOf($quiet))->toBe(0);
    expect(app(ScoreUsers::class)->forLockedUser($member->id, antiCheatNow(), 7))->toBe(28)->and(antiCheatScoreOf($member))->toBe(28);
});
