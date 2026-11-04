<?php

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\RiskSignal;
use Carbon\CarbonImmutable;

it('lists the signal kinds and outcomes by value', function () {
    // Given the two enums
    // When their values are listed
    // Then they are exactly the documented strings
    expect(RiskSignalKind::values())->toBe(['shared_device', 'ip_cluster', 'vote_burst', 'fresh_account', 'disposable_email', 'single_maker_voter', 'login_churn', 'coordinated_votes']);
    expect(RiskOutcome::values())->toBe(['confirmed', 'dismissed']);
});

it('reports open, confirmed and dismissed as the signal status', function (?RiskOutcome $outcome, string $status) {
    // Given a signal in memory with an outcome (or none)
    $signal = new RiskSignal;
    $signal->outcome = $outcome;

    // When its status is read
    // Then it is open until reviewed, then the outcome
    expect($signal->status())->toBe($status);
})->with([
    'open' => [null, 'open'],
    'confirmed' => [RiskOutcome::Confirmed, 'confirmed'],
    'dismissed' => [RiskOutcome::Dismissed, 'dismissed'],
]);

it('casts kind, outcome, details, weight and the two instants', function () {
    // Given raw attributes as the database returns them
    $signal = new RiskSignal;
    $signal->setRawAttributes([
        'kind' => 'vote_burst', 'outcome' => 'confirmed', 'details' => '{"rule":"vote_burst","observed":9}', 'weight' => '25',
        'detected_at' => '2026-10-21 12:00:00', 'reviewed_at' => '2026-10-21 12:05:00',
    ]);

    // When they are read through the casts
    // Then each has its type
    expect($signal->kind)->toBe(RiskSignalKind::VoteBurst)
        ->and($signal->outcome)->toBe(RiskOutcome::Confirmed)
        ->and($signal->details)->toBe(['rule' => 'vote_burst', 'observed' => 9])
        ->and($signal->weight)->toBe(25)
        ->and(CarbonImmutable::instance($signal->detected_at)->toDateTimeString())->toBe('2026-10-21 12:00:00')
        ->and($signal->reviewed_at->toDateTimeString())->toBe('2026-10-21 12:05:00');
});
