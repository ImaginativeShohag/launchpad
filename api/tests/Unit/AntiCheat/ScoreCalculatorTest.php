<?php

use App\Actions\AntiCheat\ScoreCalculator;
use App\Actions\AntiCheat\ScoredSignal;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use Carbon\CarbonImmutable;

function antiCheatScoreNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
}

function antiCheatScoreSignal(RiskSignalKind $kind, int $weight, float|int $ageDays = 0, ?RiskOutcome $outcome = null): ScoredSignal
{
    return new ScoredSignal($kind, $weight, antiCheatScoreNow()->subSeconds((int) round($ageDays * 86400)), $outcome);
}

it('counts a dismissed signal as zero and a confirmed one at full weight at any age', function () {
    // Given a dismissed and a confirmed signal, the confirmed one a hundred days old
    $calculator = new ScoreCalculator;

    // When they are scored
    // Then dismissed is 0 and confirmed keeps its weight
    expect($calculator->contribution(antiCheatScoreSignal(RiskSignalKind::SharedDevice, 40, 0, RiskOutcome::Dismissed), antiCheatScoreNow(), 14))->toBe(0);
    expect($calculator->contribution(antiCheatScoreSignal(RiskSignalKind::SharedDevice, 40, 100, RiskOutcome::Confirmed), antiCheatScoreNow(), 14))->toBe(40);
});

it('fades an open signal linearly to zero over the decay window', function (int $weight, float|int $age, int $expected) {
    // Given an open signal of the given weight and age (decay window 14 days)
    // When it is scored
    // Then the contribution is the faded, rounded weight
    expect((new ScoreCalculator)->contribution(antiCheatScoreSignal(RiskSignalKind::FreshAccount, $weight, $age), antiCheatScoreNow(), 14))->toBe($expected);
})->with([
    'fresh' => [40, 0, 40],
    'half of the window' => [40, 7, 20],
    'half rounds away from zero' => [15, 7, 8],
    'exactly the window' => [40, 14, 0],
    'beyond the window' => [40, 100, 0],
    'negative age (clock skew) is a fresh signal' => [40, -1, 40],
    'weight zero' => [0, 0, 0],
]);

it('takes the strongest contribution per kind and sums the kinds', function () {
    // Given ten weak signals of one kind and three of another with different weights
    $signals = [
        ...array_map(fn () => antiCheatScoreSignal(RiskSignalKind::FreshAccount, 15), range(1, 10)),
        antiCheatScoreSignal(RiskSignalKind::SharedDevice, 40), antiCheatScoreSignal(RiskSignalKind::SharedDevice, 30), antiCheatScoreSignal(RiskSignalKind::SharedDevice, 10),
    ];

    // When they are scored
    // Then each kind counts once at its maximum
    expect((new ScoreCalculator)->score($signals, antiCheatScoreNow(), 14))->toBe(55);
});

it('caps the sum at 100 and reaches the flag score only with several kinds', function (array $signals, int $expected) {
    // Given open signals at the default weights
    $list = array_map(fn (array $s) => antiCheatScoreSignal($s[0], $s[1]), $signals);

    // When they are scored
    // Then the sum is capped at 100 and one weak signal never reaches 70
    expect((new ScoreCalculator)->score($list, antiCheatScoreNow(), 14))->toBe($expected);
})->with([
    'three kinds over the cap' => [[[RiskSignalKind::SharedDevice, 40], [RiskSignalKind::CoordinatedVotes, 35], [RiskSignalKind::VoteBurst, 30]], 100],
    'device, throwaway, fresh' => [[[RiskSignalKind::SharedDevice, 40], [RiskSignalKind::DisposableEmail, 25], [RiskSignalKind::FreshAccount, 15]], 80],
    'device and ring' => [[[RiskSignalKind::SharedDevice, 40], [RiskSignalKind::CoordinatedVotes, 35]], 75],
    'device alone' => [[[RiskSignalKind::SharedDevice, 40]], 40],
    'three weaker kinds' => [[[RiskSignalKind::DisposableEmail, 25], [RiskSignalKind::LoginChurn, 20], [RiskSignalKind::SingleMakerVoter, 20]], 65],
    'nothing' => [[], 0],
]);

it('lets a confirmed signal win over a dismissed one of the same kind', function () {
    // Given a dismissed and a confirmed signal of one kind
    $signals = [antiCheatScoreSignal(RiskSignalKind::SharedDevice, 40, 0, RiskOutcome::Dismissed), antiCheatScoreSignal(RiskSignalKind::SharedDevice, 25, 30, RiskOutcome::Confirmed)];

    // When they are scored
    // Then the confirmed one counts
    expect((new ScoreCalculator)->score($signals, antiCheatScoreNow(), 14))->toBe(25);
});

it('breaks the signals down per kind with the counts by outcome', function () {
    // Given signals of two kinds in all three states
    $signals = [
        antiCheatScoreSignal(RiskSignalKind::SharedDevice, 40, 7),
        antiCheatScoreSignal(RiskSignalKind::SharedDevice, 40, 0, RiskOutcome::Dismissed),
        antiCheatScoreSignal(RiskSignalKind::SharedDevice, 20, 0, RiskOutcome::Confirmed),
        antiCheatScoreSignal(RiskSignalKind::FreshAccount, 0),
    ];

    // When the breakdown is built
    $breakdown = (new ScoreCalculator)->breakdown($signals, antiCheatScoreNow(), 14);

    // Then it lists each kind with its strongest contribution and the outcome counts, in kind order
    expect($breakdown)->toBe([
        'fresh_account' => ['contribution' => 0, 'open' => 1, 'confirmed' => 0, 'dismissed' => 0],
        'shared_device' => ['contribution' => 20, 'open' => 1, 'confirmed' => 1, 'dismissed' => 1],
    ]);
});
