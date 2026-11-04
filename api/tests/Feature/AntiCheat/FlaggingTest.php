<?php

use App\Actions\AntiCheat\FlagVotes;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\User;
use App\Models\Vote;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

it('flags only counted, active votes on recent launch days of members at the threshold', function () {
    // Given a member at 70 with votes in every state, plus a vote on a product launched two days ago
    antiCheatFreeze();
    $member = antiCheatSetScore(User::factory()->create(), 70);
    $today = antiCheatVote($member, antiCheatLiveProduct());
    $yesterday = antiCheatVote($member, antiCheatLiveProduct(antiCheatNow()->subDay()->toDateString()));
    $old = antiCheatVote($member, antiCheatLiveProduct(antiCheatNow()->subDays(2)->toDateString()));
    $withdrawn = antiCheatVote($member, antiCheatLiveProduct(), ['removed_at' => antiCheatNow(), 'toggle_count' => 1]);
    $voided = Vote::factory()->voided()->create(['user_id' => $member->id, 'product_id' => antiCheatLiveProduct()->id, 'voted_at' => antiCheatNow()]);
    $deleted = antiCheatSetScore(User::factory()->create(['deleted_at' => antiCheatNow()->subHour()]), 90);
    $ofDeleted = antiCheatVote($deleted, antiCheatLiveProduct());

    // When the flagging pass runs
    $result = antiCheatFlag();

    // Then only today's and yesterday's active counted votes were flagged
    expect($result->flagged)->toBe(2)->and($result->unflagged)->toBe(0)->and($result->failedChunks)->toBe(0);
    expect(antiCheatVoteStatus($today))->toBe('flagged')->and(antiCheatVoteStatus($yesterday))->toBe('flagged');
    expect(antiCheatVoteStatus($old))->toBe('counted')->and(antiCheatVoteStatus($withdrawn))->toBe('counted');
    expect(antiCheatVoteStatus($voided))->toBe('voided')->and(antiCheatVoteStatus($ofDeleted))->toBe('counted');
});

it('compares with >= at the threshold: 69 is not flagged, 70 is', function (int $score, string $expected) {
    // Given one member at the score
    antiCheatFreeze();
    $vote = antiCheatScoredVoter($score);

    // When the pass runs with the default threshold 70
    antiCheatFlag();

    // Then the vote is flagged exactly when the score reaches it
    expect(antiCheatVoteStatus($vote))->toBe($expected);
})->with([[69, 'counted'], [70, 'flagged'], [71, 'flagged']]);

it('follows the flag score setting live', function () {
    // Given a member at 60 and a threshold lowered to 50
    antiCheatFreeze();
    $vote = antiCheatScoredVoter(60);
    antiCheatConfig(['anticheat.flag_risk_score' => 50]);

    // When the pass runs
    antiCheatFlag();

    // Then the vote is flagged
    expect(antiCheatVoteStatus($vote))->toBe('flagged');
});

it('takes the mark off when the score falls below, and leaves voided and deleted members alone', function () {
    // Given flagged votes of a member who dropped to 10, of one still at 80, a voided one, and one of a deleted member
    antiCheatFreeze();
    $dropped = antiCheatScoredVoter(10, null, ['status' => 'flagged']);
    $still = antiCheatScoredVoter(80, null, ['status' => 'flagged']);
    $ofDeleted = antiCheatScoredVoter(0, null, ['status' => 'flagged'], ['deleted_at' => antiCheatNow()->subHour()]);
    $old = antiCheatScoredVoter(0, antiCheatNow()->subDays(2)->toDateString(), ['status' => 'flagged']);
    $voided = Vote::factory()->voided()->create(['user_id' => $dropped->user_id, 'product_id' => antiCheatLiveProduct()->id, 'voted_at' => antiCheatNow()]);

    // When the pass runs
    $result = antiCheatFlag();

    // Then only the recent one of the member below the threshold is counted again
    expect($result->unflagged)->toBe(1)->and($result->flagged)->toBe(0);
    expect(antiCheatVoteStatus($dropped))->toBe('counted')->and(antiCheatVoteStatus($still))->toBe('flagged');
    expect(antiCheatVoteStatus($ofDeleted))->toBe('flagged')->and(antiCheatVoteStatus($old))->toBe('flagged')->and(antiCheatVoteStatus($voided))->toBe('voided');
});

it('does nothing on the second run', function () {
    // Given a flagged-eligible vote that the first run flagged
    antiCheatFreeze();
    $vote = antiCheatScoredVoter(90);
    expect(antiCheatFlag()->flagged)->toBe(1);
    $updatedAt = DB::table('votes')->where('id', $vote->id)->value('updated_at');

    // When the pass runs again
    $again = antiCheatFlag();

    // Then nothing is flagged, unflagged or written
    expect($again->flagged)->toBe(0)->and($again->unflagged)->toBe(0)->and(DB::table('votes')->where('id', $vote->id)->value('updated_at'))->toBe($updatedAt);
});

it('gives the same result with one-row chunks', function () {
    // Given five eligible votes and three to unflag
    antiCheatFreeze();
    $eligible = array_map(fn () => antiCheatScoredVoter(90), range(1, 5));
    $stale = array_map(fn () => antiCheatScoredVoter(5, null, ['status' => 'flagged']), range(1, 3));

    // When the pass runs in chunks of one
    $result = antiCheatFlag(['chunk' => 1]);

    // Then every row was handled
    expect($result->flagged)->toBe(5)->and($result->unflagged)->toBe(3);
    foreach ($eligible as $vote) {
        expect(antiCheatVoteStatus($vote))->toBe('flagged');
    }
    foreach ($stale as $vote) {
        expect(antiCheatVoteStatus($vote))->toBe('counted');
    }
});

it('stops after the chunk cap and finishes the rest on the next run', function () {
    // Given three eligible votes and a cap of two chunks of one
    antiCheatFreeze();
    array_map(fn () => antiCheatScoredVoter(90), range(1, 3));

    // When the pass runs
    // Then the first run is bounded and the second completes the job
    expect(antiCheatFlag(['chunk' => 1, 'maxChunks' => 2])->flagged)->toBe(2);
    expect(antiCheatFlag(['chunk' => 1, 'maxChunks' => 2])->flagged)->toBe(1);
});

it('counts a failing chunk, reports it, and ends that direction without touching the other', function () {
    // Given a vote to flag and one to unflag, and a pass whose flag direction throws
    antiCheatFreeze();
    Exceptions::fake();
    $toFlag = antiCheatScoredVoter(90);
    $toUnflag = antiCheatScoredVoter(5, null, ['status' => 'flagged']);
    app()->bind(FlagVotes::class, fn () => new class(app(Settings::class)) extends FlagVotes
    {
        protected function flagChunk(string $from, string $to, string $comparison, int $threshold, string $since, CarbonImmutable $now, string $after, int $chunk): array
        {
            if ($to === 'flagged') {
                DB::select('select 1/0');
            }

            return parent::flagChunk($from, $to, $comparison, $threshold, $since, $now, $after, $chunk);
        }
    });

    // When the pass runs
    $result = antiCheatFlag();

    // Then the failure is counted and reported, the unflag direction still ran, and the connection works
    expect($result->failedChunks)->toBe(1)->and($result->unflagged)->toBe(1);
    Exceptions::assertReportedCount(1);
    expect(antiCheatVoteStatus($toFlag))->toBe('counted')->and(antiCheatVoteStatus($toUnflag))->toBe('counted');
    expect(DB::selectOne('select 1 as one')->one)->toBe(1);
});

it('locks vote rows only: skip locked, no product, no user, no plain for update', function () {
    // Given eligible votes in both directions
    antiCheatFreeze();
    antiCheatScoredVoter(90);
    antiCheatScoredVoter(5, null, ['status' => 'flagged']);

    // When the pass runs under the recorder
    $queries = antiCheatQueries(fn () => antiCheatFlag());

    // Then each direction is one materialised statement locking only the vote rows, and nothing else locks or updates anything
    $locking = array_values(array_filter($queries, fn (string $q) => str_contains($q, 'for no key update')));
    expect($locking)->toHaveCount(2);
    foreach ($locking as $sql) {
        expect($sql)->toContain('as materialized')->toContain('for no key update of v skip locked')->toContain('update votes set status');
    }
    expect(implode("\n", $queries))->not->toContain('for update')->not->toContain('update products')->not->toContain('update users')->not->toContain('insert into');
});

it('is wired into the detection run: a member scored over the threshold gets flagged and unflagged by dismissal', function () {
    // Given a member with three kinds of open signals (40 + 25 + 15 = 80) and a vote
    antiCheatFreeze();
    $member = User::factory()->create(['created_at' => antiCheatNow()->subDays(10)]);
    $vote = antiCheatVote($member, antiCheatLiveProduct());
    $signals = [
        antiCheatSignal($member, RiskSignalKind::SharedDevice, 40),
        antiCheatSignal($member, RiskSignalKind::DisposableEmail, 25),
        antiCheatSignal($member, RiskSignalKind::FreshAccount, 15),
    ];
    antiCheatSetScore($member, 1);

    // When the job runs
    $result = antiCheatDetect();

    // Then the member is scored 80 and the vote flagged in the same run
    expect($result->usersRescored)->toBe(1)->and($result->flagged)->toBe(1)->and(antiCheatScoreOf($member))->toBe(80)->and(antiCheatVoteStatus($vote))->toBe('flagged');

    // And once staff dismiss the weightiest signals the next run counts it again
    DB::table('risk_signals')->whereIn('id', [$signals[0]->id, $signals[1]->id])->update(['reviewed_by' => $member->id, 'reviewed_at' => antiCheatNow(), 'outcome' => RiskOutcome::Dismissed->value]);
    $again = antiCheatDetect();
    expect($again->unflagged)->toBe(1)->and(antiCheatScoreOf($member))->toBe(15)->and(antiCheatVoteStatus($vote))->toBe('counted');
});

it('is silent in a dry run for the stored data but reports real counts', function () {
    // Given a member whose signals add up to 80 (stale stored score 1) with a counted vote
    antiCheatFreeze();
    $member = User::factory()->create(['created_at' => antiCheatNow()->subDays(10)]);
    $vote = antiCheatVote($member, antiCheatLiveProduct());
    antiCheatSignal($member, RiskSignalKind::SharedDevice, 40);
    antiCheatSignal($member, RiskSignalKind::DisposableEmail, 25);
    antiCheatSignal($member, RiskSignalKind::FreshAccount, 15);
    antiCheatSetScore($member, 1);

    // When the job runs as a dry run
    $result = antiCheatDetect(['dryRun' => true]);

    // Then it counted the rescoring and the flag but nothing persisted
    expect($result->usersRescored)->toBe(1)->and($result->flagged)->toBe(1);
    expect(antiCheatVoteStatus($vote))->toBe('counted')->and(antiCheatScoreOf($member))->toBe(1);
});
