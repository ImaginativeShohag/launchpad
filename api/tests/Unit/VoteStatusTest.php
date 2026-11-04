<?php

use App\Enums\VoteStatus;

it('counts only counted and flagged votes', function (VoteStatus $status, bool $counts) {
    // Given a vote status
    // When asked whether it counts
    // Then only counted and flagged do
    expect($status->counts())->toBe($counts);
})->with([
    'counted' => [VoteStatus::Counted, true],
    'flagged' => [VoteStatus::Flagged, true],
    'voided' => [VoteStatus::Voided, false],
]);

it('lists its values in declaration order', function () {
    // Given the enum
    // When the values are read
    // Then they match the CHECK constraint values
    expect(VoteStatus::values())->toBe(['counted', 'flagged', 'voided']);
});
