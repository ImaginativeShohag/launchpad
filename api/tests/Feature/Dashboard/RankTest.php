<?php

use App\Actions\Dashboard\RankHistory;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(dashNow()));

function rankRead(Product $product, ?string $at = null): array
{
    return app(RankHistory::class)->handle($product, $at === null ? null : CarbonImmutable::parse($at, 'UTC'));
}

it('returns the launch day marks in time order with the stored rank and score', function () {
    // Given a launch today with three marks, inserted out of order
    $product = dashProduct();
    dashSnapshot($product, '2026-10-21 10:10:00', 4, 2.5);
    dashSnapshot($product, '2026-10-21 10:00:00', 7, 1.25);
    dashSnapshot($product, '2026-10-21 10:20:00', 3, 3.75);

    // When the rank history is read
    $rank = rankRead($product);

    // Then the points are ordered by time and carry rank and score as stored (never inverted or resampled)
    expect($rank['points'])->toBe([
        ['t' => '2026-10-21T10:00:00Z', 'rank' => 7, 'score' => 1.25],
        ['t' => '2026-10-21T10:10:00Z', 'rank' => 4, 'score' => 2.5],
        ['t' => '2026-10-21T10:20:00Z', 'rank' => 3, 'score' => 3.75],
    ])->and($rank['interval_minutes'])->toBe(10)->and($rank['outside_top'])->toBe(500)
        ->and($rank['launch_date'])->toBe(DASH_DAY)->and($rank['timeline_available'])->toBeTrue();
});

it('reports the best rank and the earliest mark that reached it', function () {
    // Given a best rank of 2 reached twice
    $product = dashProduct();
    dashSnapshot($product, '2026-10-21 08:00:00', 5);
    dashSnapshot($product, '2026-10-21 09:30:00', 2);
    dashSnapshot($product, '2026-10-21 09:00:00', 2);
    dashSnapshot($product, '2026-10-21 11:00:00', 3);

    // When read
    $rank = rankRead($product);

    // Then the earliest of the equal best marks is named
    expect($rank['best_rank'])->toBe(2)->and($rank['best_rank_at'])->toBe('2026-10-21T09:00:00Z');
});

it('leaves a gap where a mark is missing and shows nothing for a product outside the top 500 all day', function () {
    // Given a mark at 10:00 and 10:20 only (no 10:10), and a second product never ranked
    $ranked = dashProduct();
    dashSnapshot($ranked, '2026-10-21 10:00:00', 1);
    dashSnapshot($ranked, '2026-10-21 10:20:00', 2);
    $unranked = dashProduct();

    // When both are read
    $with = rankRead($ranked);
    $without = rankRead($unranked);

    // Then the gap is simply absent, and the unranked one is available but empty
    expect(array_column($with['points'], 't'))->toBe(['2026-10-21T10:00:00Z', '2026-10-21T10:20:00Z'])
        ->and($without['points'])->toBe([])->and($without['timeline_available'])->toBeTrue()
        ->and($without['best_rank'])->toBeNull()->and($without['best_rank_at'])->toBeNull();
});

it('never mixes in other products or other days', function () {
    // Given marks of another product, and of the day before and after this launch
    $mine = dashProduct('2026-10-20');
    $other = dashProduct('2026-10-20');
    dashSnapshot($mine, '2026-10-20 12:00:00', 4);
    dashSnapshot($mine, '2026-10-19 23:50:00', 1);
    dashSnapshot($mine, '2026-10-21 00:00:00', 1);
    dashSnapshot($other, '2026-10-20 12:00:00', 2);

    // When mine is read
    $rank = rankRead($mine);

    // Then only its own launch-day mark is there
    expect($rank['points'])->toHaveCount(1)->and($rank['points'][0]['rank'])->toBe(4)->and($rank['best_rank'])->toBe(4);
});

it('marks the timeline unavailable after the retention window and keeps the final rank', function () {
    // Given a product launched 181 days ago with a frozen final rank and an old mark (not yet purged)
    $product = dashProduct('2026-09-20');
    dashSnapshot($product, '2026-09-20 12:00:00', 3);
    dashFreeze($product, 5);

    // When read at launch + 181 days
    $rank = rankRead($product, '2027-03-20 00:00:00');

    // Then the timeline is gone but the final rank stays
    expect($rank['timeline_available'])->toBeFalse()->and($rank['points'])->toBe([])
        ->and($rank['best_rank'])->toBeNull()->and($rank['best_rank_at'])->toBeNull()
        ->and($rank['final_rank'])->toBe(5)->and($rank['frozen'])->toBeTrue();
});

it('pins the retention boundary at launch day + 179, 180 and 181 days with the default 180', function (string $at, bool $available) {
    // Given a product launched on 2026-09-20 with one mark
    $product = dashProduct('2026-09-20');
    dashSnapshot($product, '2026-09-20 12:00:00', 3);

    // When read on the given instant
    $rank = rankRead($product, $at);

    // Then the timeline is available through launch day + 180 and gone from + 181 (00:00)
    expect($rank['timeline_available'])->toBe($available)->and(count($rank['points']))->toBe($available ? 1 : 0);
})->with([
    'day 179' => ['2027-03-18 12:00:00', true],
    'day 180 end' => ['2027-03-19 23:59:59', true],
    'day 181 start' => ['2027-03-20 00:00:00', false],
    'day 181' => ['2027-03-20 12:00:00', false],
]);

it('moves the boundary with the retention setting', function () {
    // Given a product launched 11 days ago and an admin who shortens the window to 10 days
    $product = dashProduct('2026-10-10');
    dashSnapshot($product, '2026-10-10 12:00:00', 3);
    $before = rankRead($product);
    app(Settings::class)->set(SettingKey::RetentionRankSnapshotsDays, 10, signedInStaff(Role::Admin));

    // When read again
    $after = rankRead($product);

    // Then the same marks are no longer offered
    expect($before['timeline_available'])->toBeTrue()->and($after['timeline_available'])->toBeFalse()->and($after['points'])->toBe([]);
});

it('falls back to the default window when the stored retention is corrupt', function () {
    // Given a corrupt stored value (zero would purge everything) and a launch 5 days ago
    $product = dashProduct('2026-10-16');
    dashSnapshot($product, '2026-10-16 12:00:00', 3);
    retentionStoreRaw(SettingKey::RetentionRankSnapshotsDays, 0);

    // When read
    $rank = rankRead($product);

    // Then the default 180 days applies
    expect($rank['timeline_available'])->toBeTrue()->and($rank['points'])->toHaveCount(1);
});

it('is frozen only with a daily ranking row, and final_rank is returned as stored', function () {
    // Given a product launched yesterday, ranked 40th after hidden products left a gap, and one that was not frozen
    $frozen = dashProduct('2026-10-20');
    $open = dashProduct('2026-10-20');
    dashFreeze($frozen, 40);

    // When both are read
    $a = rankRead($frozen);
    $b = rankRead($open);

    // Then only the frozen one reports a final rank
    expect($a['frozen'])->toBeTrue()->and($a['final_rank'])->toBe(40)->and($b['frozen'])->toBeFalse()->and($b['final_rank'])->toBeNull();
});

it('gives the live position only on the launch day', function () {
    // Given two launches today and one yesterday
    $top = dashProduct(DASH_DAY, ['hot_score' => 9.0]);
    $second = dashProduct(DASH_DAY, ['hot_score' => 1.0]);
    $old = dashProduct('2026-10-20', ['hot_score' => 99.0]);

    // When read
    // Then today's products have their feed position and yesterday's none
    expect(rankRead($top)['current_rank'])->toBe(1)->and(rankRead($second)['current_rank'])->toBe(2)->and(rankRead($old)['current_rank'])->toBeNull();
});

it('keeps a taken-down product\'s snapshots and drops its current rank', function () {
    // Given a product taken down on its launch day after earning marks
    $product = Product::factory()->takenDown()->create(['launch_date' => DASH_DAY, 'hot_score' => 5.0]);
    dashSnapshot($product, '2026-10-21 09:00:00', 2);

    // When read
    $rank = rankRead($product);

    // Then the history stays and there is no live rank
    expect($rank['points'])->toHaveCount(1)->and($rank['best_rank'])->toBe(2)->and($rank['current_rank'])->toBeNull();
});

it('answers a product before its launch with an empty, unavailable timeline and runs no query', function (string $case) {
    // Given a draft or a scheduled product
    $product = $case === 'draft' ? Product::factory()->create() : Product::factory()->scheduled('2026-10-25')->create();

    // When read, with the stats queries recorded
    $rank = null;
    $queries = dashStatsQueries(function () use ($product, &$rank): void {
        $rank = rankRead($product);
    });

    // Then nothing is available and nothing was queried
    expect($rank['timeline_available'])->toBeFalse()->and($rank['points'])->toBe([])->and($rank['final_rank'])->toBeNull()
        ->and($rank['current_rank'])->toBeNull()->and($rank['frozen'])->toBeFalse()->and($queries)->toBe([]);
})->with(['draft', 'scheduled']);

it('reads with a fixed number of queries whatever the number of marks', function () {
    // Given 3 marks and 144 marks (a full launch day)
    $few = dashProduct();
    $many = dashProduct();
    foreach (range(0, 2) as $i) {
        dashSnapshot($few, sprintf('2026-10-21 00:%02d:00', $i * 10), 5);
    }
    foreach (range(0, 143) as $i) {
        dashSnapshot($many, sprintf('2026-10-21 %02d:%02d:00', intdiv($i, 6), ($i % 6) * 10), 5);
    }

    // When both are read
    $fewQueries = dashStatsQueries(fn () => rankRead($few));
    $manyQueries = dashStatsQueries(fn () => rankRead($many));

    // Then three statements each: the final rank, the best mark and the points
    expect($fewQueries)->toHaveCount(3)->and($manyQueries)->toHaveCount(3);
});

it('states the phase so an empty timeline can be explained', function (string $case, string $phase) {
    // Given a product in each phase
    $product = match ($case) {
        'draft' => Product::factory()->create(),
        'today' => dashProduct(),
        'earlier' => dashProduct('2026-10-10'),
    };

    // When read
    // Then the phase is in the payload
    expect(rankRead($product)['phase'])->toBe($phase);
})->with([['draft', 'before_launch'], ['today', 'launch_day'], ['earlier', 'after']]);
