<?php

use App\Actions\Ranking\HotScore;

function defaultScore(): HotScore
{
    return new HotScore(0.9, 0.35);
}

it('scores no votes as zero', function () {
    // Given no votes
    // When the score is computed
    // Then it is zero
    expect(defaultScore()->score([], 23))->toBe(0.0);
    expect(defaultScore()->score([5 => 0], 10))->toBe(0.0);
});

it('ranks the client example: 20 votes in the first hour beat 30 spread over the day', function (int $currentHour) {
    // Given 20 votes in hour 0, and 30 votes spread over the day (one per hour, plus one extra at hours 4, 8, 12, 16, 20 and 22)
    $burst = [0 => 20];
    $spread = array_fill(0, 24, 1);
    foreach ([4, 8, 12, 16, 20, 22] as $hour) {
        $spread[$hour]++;
    }
    expect(array_sum($spread))->toBe(30);

    // When both are scored at the given hour with the default constants (hours after it are ignored)
    $burstScore = defaultScore()->score($burst, $currentHour);
    $spreadScore = defaultScore()->score($spread, $currentHour);

    // Then the burst scores higher at every hour (at hour 0 the spread has only its single hour-0 vote)
    expect($burstScore)->toBeGreaterThan($spreadScore);
})->with([0, 1, 5, 12, 23]);

it('matches hand-computed values for 20 votes in hour 0', function (int $currentHour, float $expected) {
    // Given 20 votes in hour 0 and the default constants (decay 0.9, early bonus 0.35)
    // When scored at the given hour
    // Then it is 20 / (age + 2) ^ 0.9 plus 0.35 * 20
    expect(defaultScore()->score([0 => 20], $currentHour))->toEqualWithDelta($expected, 1e-6);
})->with([
    'hour 0' => [0, 17.7177346],
    'hour 23' => [23, 8.1037837],
]);

it('scores the same votes higher the later in the day they were cast (less decay)', function () {
    // Given ten votes in hour 5 and ten in hour 20, both scored at hour 23
    // When scored
    $early = defaultScore()->score([5 => 10], 23);
    $late = defaultScore()->score([20 => 10], 23);

    // Then the later votes score higher
    expect($late)->toBeGreaterThan($early);
});

it('applies the early bonus only to hours 0 and 1', function () {
    // Given ten votes in hour 1, in hour 2 and in hour 5, all scored at hour 10
    $inHour5 = defaultScore()->score([5 => 10], 10);
    $inHour1 = defaultScore()->score([1 => 10], 10);
    $inHour2 = defaultScore()->score([2 => 10], 10);

    // When compared with the same maths with the bonus switched off
    $noBonus = new HotScore(0.9, 0.0);

    // Then hour 1 gains exactly the bonus on top of its decayed value, and hour 2 and 5 gain none
    expect($inHour1)->toEqualWithDelta($noBonus->score([1 => 10], 10) + 0.35 * 10, 1e-9);
    expect($inHour2)->toEqualWithDelta($noBonus->score([2 => 10], 10), 1e-9);
    expect($inHour5)->toEqualWithDelta($noBonus->score([5 => 10], 10), 1e-9);
    expect(defaultScore()->score([1 => 10, 5 => 0], 23))->toBeGreaterThan(defaultScore()->score([5 => 10], 23));
});

it('decays old votes more with a larger exponent', function () {
    // Given the same votes and two exponents
    $gentle = (new HotScore(0.5, 0.0))->score([0 => 10, 3 => 10], 12);
    $steep = (new HotScore(2.0, 0.0))->score([0 => 10, 3 => 10], 12);

    // When compared
    // Then the larger exponent gives the lower score
    expect($steep)->toBeLessThan($gentle);
});

it('ignores hours after the current hour', function () {
    // Given votes in hours after the current one
    // When scored at hour 3
    $withFuture = defaultScore()->score([2 => 4, 4 => 100, 23 => 50], 3);
    $without = defaultScore()->score([2 => 4], 3);

    // Then they add nothing
    expect($withFuture)->toBe($without);
});

it('lets no bonus leak in from a future hour 1 when the current hour is 0', function () {
    // Given ten votes in hour 1 while the current hour is 0 (not possible in practice)
    // When scored
    // Then the vote and its bonus are both ignored
    expect(defaultScore()->score([1 => 10], 0))->toBe(0.0);
});

it('clamps a negative net to zero', function () {
    // Given more withdrawals than votes
    // When scored
    // Then the score is never negative
    expect(defaultScore()->score([0 => -3, 5 => -1], 10))->toBe(0.0);
});

it('is deterministic', function () {
    // Given the same input twice
    $input = [0 => 3, 7 => 2, 12 => 9];

    // When scored twice
    // Then the results are identical
    expect(defaultScore()->score($input, 15))->toBe(defaultScore()->score($input, 15));
});
