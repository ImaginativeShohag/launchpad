<?php

use App\Actions\Dashboard\Referrers;
use App\Actions\Dashboard\Summary;
use App\Actions\Dashboard\Window;
use App\Actions\Ranking\FreezeDay;
use App\Models\Product;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->travelTo(dashNow()));

function summaryRead(Product $product, ?string $at = null): array
{
    return app(Summary::class)->handle($product, $at === null ? null : CarbonImmutable::parse($at, 'UTC'));
}

it('returns the pinned key list in order', function () {
    // Given any product
    $product = dashProduct();

    // When the summary is read
    $summary = summaryRead($product);

    // Then the keys are exactly the spec's, in order, and nothing identifying a visitor is there
    expect(array_keys($summary))->toBe(['product_id', 'name', 'slug', 'status', 'phase', 'launch_date', 'taken_down', 'views', 'votes', 'comments', 'conversion', 'current_rank', 'best_rank', 'final_rank', 'top_source', 'timeline_available', 'as_of']);
    foreach (['ip', 'ip_hash', 'device', 'device_hash', 'hash', 'user_agent'] as $leak) {
        expect($summary)->not->toHaveKey($leak);
    }
});

it('adds up views from every bucket and reads votes and comments from the counters', function () {
    // Given buckets across hours and days, 8 votes and 3 comments on the counters
    $product = dashProduct('2026-10-20', ['votes_count' => 8, 'comments_count' => 3]);
    dashBucket($product, '2026-10-20 09:00:00', 5, 40);
    dashBucket($product, '2026-10-20 23:00:00', 3, 35);
    dashBucket($product, '2026-10-21 00:00:00', 0, 25);

    // When read
    $summary = summaryRead($product);

    // Then views are summed over all buckets, votes and comments are the counter columns
    expect($summary['views'])->toBe(100)->and($summary['votes'])->toBe(8)->and($summary['comments'])->toBe(3)
        ->and($summary['conversion'])->toBe(0.08);
});

it('rounds conversion to four places, returns null with no views and returns a value above 1 as it is', function () {
    // Given 1 vote in 3 views, 7 votes in 3 views, and 2 votes with no view
    $third = dashProduct(DASH_DAY, ['votes_count' => 1]);
    dashBucket($third, '2026-10-21 10:00:00', 1, 3);
    $above = dashProduct(DASH_DAY, ['votes_count' => 7]);
    dashBucket($above, '2026-10-21 10:00:00', 7, 3);
    $none = dashProduct(DASH_DAY, ['votes_count' => 2]);

    // When read
    // Then 0.3333, 2.3333 and null
    expect(summaryRead($third)['conversion'])->toBe(0.3333)->and(summaryRead($above)['conversion'])->toBe(2.3333)->and(summaryRead($none)['conversion'])->toBeNull();
});

it('reports the phase for each state of a product', function (string $case, string $phase, ?string $launch, bool $takenDown) {
    // Given a product in the state
    $product = match ($case) {
        'draft' => Product::factory()->create(),
        'scheduled' => Product::factory()->scheduled('2026-10-25')->create(),
        'live today' => dashProduct(),
        'live yesterday' => dashProduct('2026-10-20'),
        'taken down today' => Product::factory()->takenDown()->create(['launch_date' => DASH_DAY, 'hot_score' => 3.0]),
        'taken down earlier' => Product::factory()->takenDown()->create(['launch_date' => '2026-10-10']),
    };

    // When read
    $summary = summaryRead($product);

    // Then phase, launch date and the taken-down flag follow; a taken-down product has no current rank
    expect($summary['phase'])->toBe($phase)->and($summary['launch_date'])->toBe($launch)->and($summary['taken_down'])->toBe($takenDown);
    if ($takenDown) {
        expect($summary['current_rank'])->toBeNull();
    }
})->with([
    'draft' => ['draft', 'before_launch', null, false],
    'scheduled' => ['scheduled', 'before_launch', '2026-10-25', false],
    'live today' => ['live today', 'launch_day', DASH_DAY, false],
    'live yesterday' => ['live yesterday', 'after', '2026-10-20', false],
    'taken down today' => ['taken down today', 'launch_day', DASH_DAY, true],
    'taken down earlier' => ['taken down earlier', 'after', '2026-10-10', true],
]);

it('returns zeros and nulls for a draft', function () {
    // Given a draft
    $summary = summaryRead(Product::factory()->create());

    // Then nothing is counted
    expect($summary)->toMatchArray([
        'status' => 'draft', 'phase' => 'before_launch', 'launch_date' => null, 'views' => 0, 'votes' => 0, 'comments' => 0, 'conversion' => null,
        'current_rank' => null, 'best_rank' => null, 'final_rank' => null, 'top_source' => null, 'timeline_available' => false,
    ]);
});

it('gives the live feed position on the launch day and null the day after', function () {
    // Given a launch today ranked second, and the same product read the next day
    dashProduct(DASH_DAY, ['hot_score' => 9.0]);
    $product = dashProduct(DASH_DAY, ['hot_score' => 1.0]);

    // When read today and tomorrow
    $today = summaryRead($product);
    $tomorrow = summaryRead($product, '2026-10-22 08:00:00');

    // Then it is 2 today and null after the day
    expect($today['current_rank'])->toBe(2)->and($tomorrow['current_rank'])->toBeNull()->and($tomorrow['phase'])->toBe('after');
});

it('shows the final rank only once the day is frozen, as stored', function () {
    // Given a launch yesterday with votes, read before and after the real freeze
    $product = dashProduct('2026-10-20', ['votes_count' => 4]);
    dashBucket($product, '2026-10-20 10:00:00', 4, 0);
    $before = summaryRead($product);

    // When the day is frozen by the real action
    app(FreezeDay::class)->handle(CarbonImmutable::now('UTC'));
    $after = summaryRead($product);

    // Then the rank appears
    expect($before['final_rank'])->toBeNull()->and($after['final_rank'])->toBe(1);
});

it('returns a gap in the final rank as stored', function () {
    // Given a stored final rank of 40
    $product = dashProduct('2026-10-20');
    dashFreeze($product, 40);

    // When read
    // Then 40
    expect(summaryRead($product)['final_rank'])->toBe(40);
});

it('takes best_rank and timeline_available from the snapshots', function () {
    // Given a launch today with two marks
    $product = dashProduct();
    dashSnapshot($product, '2026-10-21 09:00:00', 6);
    dashSnapshot($product, '2026-10-21 09:10:00', 3);

    // When read
    $summary = summaryRead($product);

    // Then the best is 3 and the timeline is available
    expect($summary['best_rank'])->toBe(3)->and($summary['timeline_available'])->toBeTrue();
});

it('names the top source by visits then by name, with its category', function () {
    // Given two sources on a tie and a smaller one, spread over days
    $product = dashProduct('2026-10-20');
    dashReferrer($product, '2026-10-20', 'x', 5);
    dashReferrer($product, '2026-10-21', 'x', 5);
    dashReferrer($product, '2026-10-21', 'google', 10);
    dashReferrer($product, '2026-10-21', 'direct', 4);

    // When read
    $top = summaryRead($product)['top_source'];

    // Then google wins the tie by name and is a search source
    expect($top)->toBe(['source' => 'google', 'category' => 'search', 'visits' => 10]);
});

it('gives a host source the other category', function () {
    // Given only a long-tail host
    $product = dashProduct();
    dashReferrer($product, DASH_DAY, 'blog.example.org', 3);

    // When read
    // Then it is categorised as other
    expect(summaryRead($product)['top_source'])->toBe(['source' => 'blog.example.org', 'category' => 'other', 'visits' => 3]);
});

it('formats as_of as an ISO instant', function () {
    // Given the frozen clock
    // When read
    // Then as_of is that instant
    expect(summaryRead(dashProduct())['as_of'])->toBe('2026-10-21T12:00:00Z');
});

it('reads with a constant number of statements', function () {
    // Given a product with plenty of data and one with none
    $busy = dashProduct();
    foreach (range(0, 20) as $h) {
        dashBucket($busy, sprintf('2026-10-21 %02d:00:00', $h), 1, 1);
    }
    foreach (['x', 'google', 'direct', 'reddit'] as $source) {
        dashReferrer($busy, DASH_DAY, $source, 2);
    }
    $quiet = dashProduct();

    // When both are read with every statement recorded (a first read warms the settings cache, which is not part of the read)
    summaryRead($quiet);
    $busyQueries = dashAllQueries(fn () => summaryRead($busy));
    $quietQueries = dashAllQueries(fn () => summaryRead($quiet));

    // Then the count is the same and small
    expect(count($busyQueries))->toBe(count($quietQueries))->and(count($busyQueries))->toBeLessThanOrEqual(9);
});

it('agrees with the referrers list on ties whatever the database collation', function () {
    // Given sources that a locale collation orders differently from byte order (underscore, dot, upper case)
    $product = dashProduct();
    foreach (['hacker_news', 'github', 'Zeta.example.com', 'a.example.com'] as $source) {
        dashReferrer($product, DASH_DAY, $source, 5);
    }
    $window = Window::range($product, null, null, CarbonImmutable::now('UTC'));

    // When the top source and the first referrer row are read
    $top = summaryRead($product)['top_source']['source'];
    $first = app(Referrers::class)->handle($product, $window)['sources'][0]['source'];

    // Then both name the byte-order first
    expect($top)->toBe('Zeta.example.com')->and($first)->toBe($top);
});
