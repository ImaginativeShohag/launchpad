<?php

use App\Actions\Dashboard\Referrers;
use App\Actions\Dashboard\Window;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(dashNow()));

function referrersRead(Product $product, ?string $from = null, ?string $to = null): array
{
    $window = Window::range($product, $from, $to, CarbonImmutable::now('UTC'));
    expect($window->errors())->toBe([]);

    return app(Referrers::class)->handle($product, $window);
}

it('computes visits and shares that add up to about one', function () {
    // Given three sources
    $product = dashProduct('2026-10-19');
    dashReferrer($product, '2026-10-20', 'google', 50);
    dashReferrer($product, '2026-10-21', 'google', 25);
    dashReferrer($product, '2026-10-21', 'x', 15);
    dashReferrer($product, '2026-10-21', 'direct', 10);

    // When read from launch to today
    $read = referrersRead($product);

    // Then sources are ordered with shares, and the total is the sum
    expect($read['total'])->toBe(100)->and($read['from'])->toBe('2026-10-19')->and($read['to'])->toBe(DASH_DAY)
        ->and($read['sources'])->toBe([
            ['source' => 'google', 'category' => 'search', 'visits' => 75, 'share' => 0.75],
            ['source' => 'x', 'category' => 'social', 'visits' => 15, 'share' => 0.15],
            ['source' => 'direct', 'category' => 'direct', 'visits' => 10, 'share' => 0.1],
        ])->and(array_sum(array_column($read['sources'], 'share')))->toEqualWithDelta(1.0, 0.001);
});

it('breaks ties by name', function () {
    // Given three sources with equal visits
    $product = dashProduct();
    foreach (['reddit', 'bing', 'x'] as $source) {
        dashReferrer($product, DASH_DAY, $source, 4);
    }

    // When read
    // Then they are in name order
    expect(array_column(referrersRead($product)['sources'], 'source'))->toBe(['bing', 'reddit', 'x']);
});

it('keeps the top ten and folds the rest into one other row merged with a real other source', function () {
    // Given 13 sources: ten large, a small real `other` and two small hosts
    $product = dashProduct();
    foreach (range(1, 10) as $i) {
        dashReferrer($product, DASH_DAY, 'site-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.example.com', 100 - $i);
    }
    dashReferrer($product, DASH_DAY, 'other', 3);
    dashReferrer($product, DASH_DAY, 'tiny-a.example.com', 2);
    dashReferrer($product, DASH_DAY, 'tiny-b.example.com', 1);

    // When read
    $read = referrersRead($product);

    // Then ten sources plus one other row of 3 + 2 + 1, and the total still counts everything
    expect($read['sources'])->toHaveCount(11)->and(end($read['sources']))->toBe(['source' => 'other', 'category' => 'other', 'visits' => 6, 'share' => round(6 / $read['total'], 4)])
        ->and($read['total'])->toBe(array_sum(array_map(fn ($i) => 100 - $i, range(1, 10))) + 6);
});

it('merges the fold into a real other source that is inside the top ten and keeps the order', function () {
    // Given a big real other plus eleven hosts
    $product = dashProduct();
    dashReferrer($product, DASH_DAY, 'other', 50);
    foreach (range(1, 11) as $i) {
        dashReferrer($product, DASH_DAY, 'h'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.example.com', 20 - $i);
    }

    // When read
    $read = referrersRead($product);

    // Then `other` appears once, carries the folded host, and the list is still visits-descending
    $others = array_values(array_filter($read['sources'], fn ($s) => $s['source'] === 'other'));
    expect($others)->toHaveCount(1)->and($others[0]['visits'])->toBe(50 + 10 + 9)->and($read['sources'])->toHaveCount(10);
    $visits = array_column($read['sources'], 'visits');
    $sorted = $visits;
    rsort($sorted);
    expect($visits)->toBe($sorted);
});

it('groups by category for direct, internal, search, social and other', function () {
    // Given one source of each category and two social
    $product = dashProduct();
    dashReferrer($product, DASH_DAY, 'direct', 1);
    dashReferrer($product, DASH_DAY, 'launchpad', 2);
    dashReferrer($product, DASH_DAY, 'google', 3);
    dashReferrer($product, DASH_DAY, 'x', 4);
    dashReferrer($product, DASH_DAY, 'reddit', 5);
    dashReferrer($product, DASH_DAY, 'blog.example.org', 6);

    // When read
    $read = referrersRead($product);

    // Then the categories are summed, ordered by visits then name
    expect($read['by_category'])->toBe([
        ['category' => 'social', 'visits' => 9, 'share' => round(9 / 21, 4)],
        ['category' => 'other', 'visits' => 6, 'share' => round(6 / 21, 4)],
        ['category' => 'search', 'visits' => 3, 'share' => round(3 / 21, 4)],
        ['category' => 'internal', 'visits' => 2, 'share' => round(2 / 21, 4)],
        ['category' => 'direct', 'visits' => 1, 'share' => round(1 / 21, 4)],
    ]);
});

it('counts the categories of folded sources too', function () {
    // Given twelve social-ish hosts of one category beyond the top ten
    $product = dashProduct();
    foreach (['x', 'facebook', 'linkedin', 'reddit', 'hacker_news', 'youtube', 'mastodon', 'bluesky', 'github'] as $i => $source) {
        dashReferrer($product, DASH_DAY, $source, 20 - $i);
    }
    dashReferrer($product, DASH_DAY, 'a.example.com', 2);
    dashReferrer($product, DASH_DAY, 'b.example.com', 1);

    // When read
    $read = referrersRead($product);

    // Then the folded host stays in the `other` category total
    $other = collect($read['by_category'])->firstWhere('category', 'other');
    expect($other['visits'])->toBe(3);
});

it('filters the window by day, inclusive on both ends', function () {
    // Given visits on four days
    $product = dashProduct('2026-10-18');
    foreach (['2026-10-18' => 1, '2026-10-19' => 10, '2026-10-20' => 100, '2026-10-21' => 1000] as $day => $visits) {
        dashReferrer($product, $day, 'x', $visits);
    }

    // When the middle two days are asked
    $read = referrersRead($product, '2026-10-19', '2026-10-20');

    // Then both end days count and no other
    expect($read['total'])->toBe(110)->and($read['from'])->toBe('2026-10-19')->and($read['to'])->toBe('2026-10-20');
});

it('refuses a window of 367 days and accepts 366', function () {
    // Given an old product
    $product = dashProduct('2025-01-01');

    // When the caps are tried
    $ok = Window::range($product, '2025-10-21', '2026-10-21', CarbonImmutable::now('UTC'));
    $tooLong = Window::range($product, '2025-10-20', '2026-10-21', CarbonImmutable::now('UTC'));

    // Then 366 passes and 367 is an error
    expect($ok->errors())->toBe([])->and($tooLong->errors())->toHaveKey('to');
});

it('answers an empty window with a zero total and empty lists', function () {
    // Given a launched product with no referrers, and a draft
    $launched = dashProduct();
    $draft = Product::factory()->create();

    // When read
    // Then total 0 and empty arrays (dates for the launched one, null for the draft)
    expect(referrersRead($launched))->toBe(['from' => DASH_DAY, 'to' => DASH_DAY, 'total' => 0, 'sources' => [], 'by_category' => []])
        ->and(referrersRead($draft))->toBe(['from' => null, 'to' => null, 'total' => 0, 'sources' => [], 'by_category' => []]);
});

it('equals the window\'s summed views in a consistent dataset', function () {
    // Given buckets and referrer rows that obey the flush invariant (per day, sum of visits = sum of views)
    $product = dashProduct('2026-10-20');
    dashBucket($product, '2026-10-20 10:00:00', 0, 7);
    dashBucket($product, '2026-10-20 15:00:00', 0, 3);
    dashBucket($product, '2026-10-21 08:00:00', 0, 5);
    dashReferrer($product, '2026-10-20', 'x', 6);
    dashReferrer($product, '2026-10-20', 'direct', 4);
    dashReferrer($product, '2026-10-21', 'google', 5);

    // When both are read
    $views = (int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('views');

    // Then the referrer total is the views
    expect(referrersRead($product)['total'])->toBe($views)->toBe(15);
});

it('never leaks the sources of another product', function () {
    // Given two products with different sources
    $mine = dashProduct();
    $other = dashProduct();
    dashReferrer($mine, DASH_DAY, 'x', 1);
    dashReferrer($other, DASH_DAY, 'secret-source.example.com', 99);

    // When mine is read
    $read = referrersRead($mine);

    // Then only its own source is there
    expect($read['total'])->toBe(1)->and(array_column($read['sources'], 'source'))->toBe(['x'])->and(json_encode($read))->not->toContain('secret-source');
});

it('reads with one statement whatever the number of sources', function () {
    // Given 3 and 40 sources
    $few = dashProduct();
    $many = dashProduct();
    foreach (range(1, 3) as $i) {
        dashReferrer($few, DASH_DAY, "s{$i}.example.com", $i);
    }
    foreach (range(1, 40) as $i) {
        dashReferrer($many, DASH_DAY, "s{$i}.example.com", $i);
    }

    // When both are read
    $a = dashStatsQueries(fn () => referrersRead($few));
    $b = dashStatsQueries(fn () => referrersRead($many));

    // Then one statement each
    expect($a)->toHaveCount(1)->and($b)->toHaveCount(1);
});
