<?php

use App\Actions\Dashboard\Series;
use App\Actions\Dashboard\Window;
use App\Models\Product;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->travelTo(dashNow()));

/** @return array{granularity: string, from: ?string, to: ?string, points: list<array<string, mixed>>} */
function seriesRead(Product $product, ?string $granularity = null, ?string $from = null, ?string $to = null): array
{
    $window = Window::series($product, $granularity, $from, $to, CarbonImmutable::now('UTC'));
    expect($window->errors())->toBe([]);

    return app(Series::class)->handle($product, $window);
}

function seriesErrors(Product $product, ?string $granularity, ?string $from, ?string $to, string $today = DASH_DAY): array
{
    return Window::series($product, $granularity, $from, $to, CarbonImmutable::parse($today, 'UTC'))->errors();
}

it('defaults the hour series to the launch day with 24 zero-filled points and running totals', function () {
    // Given a launch today with votes and views in three of its hours
    $product = dashProduct();
    dashBucket($product, '2026-10-21 00:00:00', 1, 10);
    dashBucket($product, '2026-10-21 09:00:00', 4, 0);
    dashBucket($product, '2026-10-21 11:00:00', 2, 5);

    // When the default series is read
    $series = seriesRead($product);

    // Then it has the 24 hours of the launch day, zero where nothing happened, and cumulative values
    expect($series['granularity'])->toBe('hour')->and($series['from'])->toBe(DASH_DAY)->and($series['to'])->toBe(DASH_DAY)
        ->and($series['points'])->toHaveCount(24)
        ->and($series['points'][0])->toBe(['t' => '2026-10-21T00:00:00Z', 'votes' => 1, 'views' => 10, 'cumulative_votes' => 1, 'cumulative_views' => 10])
        ->and($series['points'][1])->toBe(['t' => '2026-10-21T01:00:00Z', 'votes' => 0, 'views' => 0, 'cumulative_votes' => 1, 'cumulative_views' => 10])
        ->and($series['points'][9])->toBe(['t' => '2026-10-21T09:00:00Z', 'votes' => 4, 'views' => 0, 'cumulative_votes' => 5, 'cumulative_views' => 10])
        ->and($series['points'][11])->toBe(['t' => '2026-10-21T11:00:00Z', 'votes' => 2, 'views' => 5, 'cumulative_votes' => 7, 'cumulative_views' => 15])
        ->and($series['points'][23]['cumulative_votes'])->toBe(7)->and($series['points'][23]['cumulative_views'])->toBe(15);
});

it('sums the UTC day for the day series and puts 23:00 and 00:00 on different days', function () {
    // Given a launch two days ago with buckets around two midnights
    $product = dashProduct('2026-10-19');
    dashBucket($product, '2026-10-19 22:00:00', 3, 1);
    dashBucket($product, '2026-10-19 23:00:00', 2, 2);
    dashBucket($product, '2026-10-20 00:00:00', 5, 4);
    dashBucket($product, '2026-10-21 00:00:00', 1, 0);

    // When the day series is read from the launch to today
    $series = seriesRead($product, 'day');

    // Then the 23:00 bucket belongs to its own day and 00:00 starts the next one
    expect($series['points'])->toBe([
        ['t' => '2026-10-19T00:00:00Z', 'votes' => 5, 'views' => 3, 'cumulative_votes' => 5, 'cumulative_views' => 3],
        ['t' => '2026-10-20T00:00:00Z', 'votes' => 5, 'views' => 4, 'cumulative_votes' => 10, 'cumulative_views' => 7],
        ['t' => '2026-10-21T00:00:00Z', 'votes' => 1, 'views' => 0, 'cumulative_votes' => 11, 'cumulative_views' => 7],
    ]);
});

it('starts cumulative values from the totals before the window so a later window continues the same curve', function () {
    // Given a launch four days ago with something every day
    $product = dashProduct('2026-10-17');
    foreach (['17' => [1, 10], '18' => [2, 20], '19' => [3, 30], '20' => [4, 40], '21' => [5, 50]] as $day => [$votes, $views]) {
        dashBucket($product, "2026-10-{$day} 10:00:00", $votes, $views);
    }
    $full = seriesRead($product, 'day');

    // When a window starting on day 3 is read
    $later = seriesRead($product, 'day', '2026-10-19', '2026-10-21');

    // Then its points are the tail of the full curve, cumulative values included
    expect($later['points'])->toBe(array_slice($full['points'], 2))
        ->and($later['points'][0]['cumulative_votes'])->toBe(6)->and($later['points'][0]['cumulative_views'])->toBe(60);
});

it('continues the hour curve across windows too', function () {
    // Given a launch yesterday with buckets on both days
    $product = dashProduct('2026-10-20');
    dashBucket($product, '2026-10-20 05:00:00', 7, 70);
    dashBucket($product, '2026-10-21 05:00:00', 1, 10);

    // When only today's hours are read
    $series = seriesRead($product, 'hour', '2026-10-21', '2026-10-21');

    // Then the first point already carries yesterday's totals
    expect($series['points'])->toHaveCount(24)
        ->and($series['points'][0]['cumulative_votes'])->toBe(7)->and($series['points'][0]['cumulative_views'])->toBe(70)
        ->and($series['points'][5]['cumulative_votes'])->toBe(8);
});

it('clamps from up to the launch date and to down to today', function () {
    // Given a product launched two days ago
    $product = dashProduct('2026-10-19');
    dashBucket($product, '2026-10-19 12:00:00', 1, 1);

    // When a day window reaches before the launch and into the future
    $series = seriesRead($product, 'day', '2026-10-10', '2026-10-25');

    // Then it is the launch to today
    expect($series['from'])->toBe('2026-10-19')->and($series['to'])->toBe('2026-10-21')->and($series['points'])->toHaveCount(3);
});

it('never counts hours before the launch day, not even in the opening totals', function () {
    // Given a bucket the day before the launch (not producible by the app, so a boundary check) and one on the launch day
    $product = dashProduct('2026-10-20');
    dashBucket($product, '2026-10-19 23:00:00', 50, 500);
    dashBucket($product, '2026-10-20 00:00:00', 2, 3);

    // When the series is read from the launch day
    $day = seriesRead($product, 'day');
    $hour = seriesRead($product, 'hour', '2026-10-21', '2026-10-21');

    // Then the earlier hour is in neither the points nor the cumulative base
    expect($day['points'][0])->toBe(['t' => '2026-10-20T00:00:00Z', 'votes' => 2, 'views' => 3, 'cumulative_votes' => 2, 'cumulative_views' => 3])
        ->and($hour['points'][0]['cumulative_votes'])->toBe(2)->and($hour['points'][0]['cumulative_views'])->toBe(3);
});

it('accepts windows of exactly 7 days (hour) and 366 days (day) and refuses one more', function () {
    // Given a product launched a year and a half ago
    $product = dashProduct('2025-01-01');

    // When the limits are tried
    // Then exactly the cap passes and one day more is an error
    expect(seriesErrors($product, 'hour', '2026-10-15', '2026-10-21'))->toBe([])
        ->and(seriesErrors($product, 'hour', '2026-10-14', '2026-10-21'))->toHaveKey('to')
        ->and(seriesErrors($product, 'day', '2025-10-21', '2026-10-21'))->toBe([])
        ->and(seriesErrors($product, 'day', '2025-10-20', '2026-10-21'))->toHaveKey('to');
});

it('refuses from after to', function () {
    // Given a live product
    $product = dashProduct('2026-10-01');

    // When from is after to
    // Then it is a from error
    expect(seriesErrors($product, 'day', '2026-10-10', '2026-10-09'))->toHaveKey('from')
        ->and(seriesErrors($product, 'day', '2026-10-10', '2026-10-10'))->toBe([]);
});

it('never exceeds the caps by default, even for a product older than 366 days', function () {
    // Given a product launched 500 days ago, with a bucket on its launch day and one today
    $product = dashProduct('2025-06-09');
    dashBucket($product, '2025-06-09 10:00:00', 9, 9);
    dashBucket($product, '2026-10-21 10:00:00', 1, 1);

    // When the default day series is read
    $series = seriesRead($product, 'day');

    // Then it is the last 366 days, and the old votes are in the opening totals
    expect($series['points'])->toHaveCount(366)->and($series['to'])->toBe(DASH_DAY)->and($series['from'])->toBe('2025-10-21')
        ->and($series['points'][0]['cumulative_votes'])->toBe(9)->and($series['points'][365]['cumulative_votes'])->toBe(10);
});

it('returns an empty series for an hour window that starts after today', function () {
    // Given a product that launched yesterday
    $product = dashProduct('2026-10-20');
    $window = Window::series($product, 'hour', '2026-10-23', null, CarbonImmutable::now('UTC'));

    // When only `from` is given and it is in the future
    $series = app(Series::class)->handle($product, $window);

    // Then the window is empty, not an error
    expect($window->errors())->toBe([])->and($window->empty)->toBeTrue()->and($series['points'])->toBe([]);
});

it('runs an hour window with only from for up to 7 days, ending at today', function () {
    // Given a product launched 10 days ago with a bucket on day 3 and on today
    $product = dashProduct('2026-10-11');
    dashBucket($product, '2026-10-13 05:00:00', 2, 2);
    dashBucket($product, '2026-10-21 05:00:00', 1, 1);

    // When from is day 3 (no to), and when from is only 2 days ago
    $long = seriesRead($product, 'hour', '2026-10-13');
    $short = seriesRead($product, 'hour', '2026-10-19');

    // Then the first runs 7 days from `from`, the second ends at today
    expect($long['from'])->toBe('2026-10-13')->and($long['to'])->toBe('2026-10-19')->and($long['points'])->toHaveCount(168)->and($long['points'][5]['votes'])->toBe(2)
        ->and($short['to'])->toBe(DASH_DAY)->and($short['points'])->toHaveCount(72)->and($short['points'][71 - 18]['votes'])->toBe(1);
});

it('applies the cap after clamping from when to is not written', function () {
    // Given a recent product and an old one
    $recent = dashProduct('2026-10-19');
    $old = dashProduct('2025-01-01');

    // When from is far before the launch (day) and when from is more than 366 days back for the old one
    // Then the first is valid and clamped, and the second is still too long once clamped
    $window = Window::series($recent, 'day', '2000-01-01', null, CarbonImmutable::now('UTC'));
    expect($window->errors())->toBe([])->and($window->from?->toDateString())->toBe('2026-10-19')->and($window->to?->toDateString())->toBe(DASH_DAY)
        ->and(seriesErrors($old, 'day', '2025-01-01', null))->toHaveKey('from')
        ->and(Window::range($recent, '2000-01-01', null, CarbonImmutable::now('UTC'))->errors())->toBe([])
        ->and(Window::range($old, '2025-01-01', null, CarbonImmutable::now('UTC'))->errors())->toHaveKey('from')
        ->and(seriesErrors($old, 'day', '2025-01-01', '2026-10-21'))->toHaveKey('to');
});

it('shows a negative net vote as stored', function () {
    // Given a vote withdrawn in a later hour
    $product = dashProduct();
    dashBucket($product, '2026-10-21 08:00:00', 3, 0);
    dashBucket($product, '2026-10-21 10:00:00', -2, 0);

    // When the hours are read
    $series = seriesRead($product);

    // Then the later hour is -2 and the running total follows
    expect($series['points'][10]['votes'])->toBe(-2)->and($series['points'][10]['cumulative_votes'])->toBe(1);
});

it('answers a product before its launch with no points and null dates', function (string $case) {
    // Given a draft, a scheduled and an approved-not-live product
    $product = match ($case) {
        'draft' => Product::factory()->create(),
        'scheduled' => Product::factory()->scheduled('2026-10-25')->create(),
    };

    // When any series is read
    $hour = seriesRead($product);
    $day = seriesRead($product, 'day', '2026-10-01', '2026-10-05');

    // Then both are empty and echo no dates
    expect($hour)->toBe(['granularity' => 'hour', 'from' => null, 'to' => null, 'points' => []])
        ->and($day)->toBe(['granularity' => 'day', 'from' => null, 'to' => null, 'points' => []]);
})->with(['draft', 'scheduled']);

it('still enforces ordering and the cap for a product before its launch', function () {
    // Given a draft
    $product = Product::factory()->create();

    // When an impossible window is asked
    // Then the shape rules apply even without a launch date
    expect(seriesErrors($product, 'hour', '2026-10-10', '2026-10-01'))->toHaveKey('from')
        ->and(seriesErrors($product, 'hour', '2026-10-01', '2026-10-31'))->toHaveKey('to');
});

it('reads a taken-down product like a live one', function () {
    // Given a product taken down on its launch day
    $product = Product::factory()->takenDown()->create(['launch_date' => DASH_DAY]);
    dashBucket($product, '2026-10-21 03:00:00', 4, 6);

    // When the series is read
    $series = seriesRead($product);

    // Then its history is intact
    expect($series['points'][3]['votes'])->toBe(4)->and($series['points'])->toHaveCount(24);
});

it('reads the series with a constant number of queries whatever the number of buckets', function () {
    // Given one product with 5 buckets and one with 24
    $few = dashProduct();
    $many = dashProduct();
    foreach (range(0, 4) as $h) {
        dashBucket($few, sprintf('2026-10-21 %02d:00:00', $h), 1, 1);
    }
    foreach (range(0, 23) as $h) {
        dashBucket($many, sprintf('2026-10-21 %02d:00:00', $h), 1, 1);
    }

    // When both are read with their queries recorded
    $fewQueries = dashStatsQueries(fn () => seriesRead($few));
    $manyQueries = dashStatsQueries(fn () => seriesRead($many));

    // Then two statements each (the opening totals and the points)
    expect($fewQueries)->toHaveCount(2)->and($manyQueries)->toHaveCount(2);
});
