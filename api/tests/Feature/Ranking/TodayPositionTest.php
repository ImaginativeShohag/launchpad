<?php

use App\Actions\Ranking\TodayRanking;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(dashNow()));

it('matches the feed order for ranks 1 to 100 on tied data', function () {
    // Given 105 launches today where scores and votes tie in groups, so ids break many of the ties
    $maker = User::factory()->create();
    foreach (range(1, 105) as $i) {
        dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'hot_score' => (float) intdiv($i, 20), 'votes_count' => $i % 3]);
    }
    $ranking = app(TodayRanking::class);

    // When the position of every product in the feed's top 100 is asked
    $feed = $ranking->ranking(positionNow(), 100);
    $positions = $feed->map(fn (Product $p) => $ranking->positionOf(positionNow(), $p))->all();

    // Then each position is its 1-based index in the feed (the same order, ties included)
    expect($feed)->toHaveCount(100)->and($positions)->toBe(range(1, 100));
});

it('counts correctly beyond the feed limit of 100', function () {
    // Given 130 launches today with strictly decreasing scores
    $maker = User::factory()->create();
    $products = [];
    foreach (range(1, 130) as $i) {
        $products[$i] = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'hot_score' => (float) (1000 - $i), 'votes_count' => 0]);
    }

    // When the 120th best is asked, and the feed is limited to 100 rows
    $position = app(TodayRanking::class)->positionOf(positionNow(), $products[120]);

    // Then it is 120 although the feed never lists it
    expect($position)->toBe(120)
        ->and(app(TodayRanking::class)->ranking(positionNow(), 100))->toHaveCount(100)
        ->and(app(TodayRanking::class)->positionOf(positionNow(), $products[130]))->toBe(130);
});

it('breaks ties by votes then id exactly like the feed', function () {
    // Given a score tie with different votes, and a full tie
    $few = dashProduct(DASH_DAY, ['hot_score' => 2.0, 'votes_count' => 1]);
    $many = dashProduct(DASH_DAY, ['hot_score' => 2.0, 'votes_count' => 9]);
    [$tieA, $tieB] = [dashProduct(DASH_DAY, ['hot_score' => 1.0, 'votes_count' => 0]), dashProduct(DASH_DAY, ['hot_score' => 1.0, 'votes_count' => 0])];
    $byId = collect([$tieA, $tieB])->sortBy('id')->values();

    // When the positions are asked
    $ranking = app(TodayRanking::class);

    // Then more votes win a score tie and the lower id wins a full tie
    expect($ranking->positionOf(positionNow(), $many))->toBe(1)
        ->and($ranking->positionOf(positionNow(), $few))->toBe(2)
        ->and($ranking->positionOf(positionNow(), $byId[0]))->toBe(3)
        ->and($ranking->positionOf(positionNow(), $byId[1]))->toBe(4);
});

it('keeps full double precision when comparing scores', function () {
    // Given two scores that differ only after the 14th significant digit, the LOWER id holding the LOWER score
    // (so a precision collapse would let the id tie-break put the wrong product first)
    $lower = dashProduct(DASH_DAY, ['hot_score' => 1.0, 'votes_count' => 0]);
    $higher = dashProduct(DASH_DAY, ['hot_score' => 1.0, 'votes_count' => 0]);
    // Written as an SQL literal: a PHP float is bound as a rounded string and would collapse before it reaches the table
    DB::statement('UPDATE products SET hot_score = 1.0000000000000002::float8 WHERE id = ?', [$higher->id]);
    expect(DB::table('products')->where('id', $higher->id)->value('hot_score'))->toBeGreaterThan(1.0);
    expect($lower->id)->toBeLessThan($higher->id);

    // When their positions are asked
    $ranking = app(TodayRanking::class);

    // Then the higher score is first in both the feed and positionOf
    expect($ranking->positionOf(positionNow(), $higher))->toBe(1)->and($ranking->positionOf(positionNow(), $lower))->toBe(2)
        ->and($ranking->ranking(positionNow())->pluck('id')->all())->toBe([$higher->id, $lower->id]);
});

it('is null for a product that is not on today\'s list', function (string $case) {
    // Given a live launch today that is on the list, and another product in a state that keeps it off
    dashProduct(DASH_DAY, ['hot_score' => 5.0]);
    $product = match ($case) {
        'draft' => Product::factory()->create(),
        'taken down' => Product::factory()->takenDown()->create(['launch_date' => DASH_DAY]),
        'future launch' => Product::factory()->scheduled('2026-10-25')->create(),
        'yesterday' => dashProduct('2026-10-20'),
        'deleted maker' => dashProduct(DASH_DAY, ['maker_id' => User::factory()->create(['deleted_at' => now()])->id]),
        'soft deleted' => dashProduct(DASH_DAY, ['deleted_at' => now()]),
    };

    // When its position is asked
    $position = app(TodayRanking::class)->positionOf(positionNow(), $product);

    // Then it has none
    expect($position)->toBeNull();
})->with(['draft', 'taken down', 'future launch', 'yesterday', 'deleted maker', 'soft deleted']);

it('reads the product fresh, so a stale instance cannot misplace it', function () {
    // Given two products and a stale in-memory copy of the second
    $first = dashProduct(DASH_DAY, ['hot_score' => 5.0]);
    $second = dashProduct(DASH_DAY, ['hot_score' => 1.0]);
    $stale = Product::query()->findOrFail($second->id);

    // When the second overtakes the first in the database only
    Product::query()->whereKey($second->id)->update(['hot_score' => 9.0]);

    // Then the position follows the stored row, not the instance
    expect(app(TodayRanking::class)->positionOf(positionNow(), $stale))->toBe(1)
        ->and(app(TodayRanking::class)->positionOf(positionNow(), $first))->toBe(2);
});

it('agrees with the feed at every rank (a test that fails when positionOf and ranking disagree)', function () {
    // Given a mixed day: ties, a hidden product and a late-created higher id
    foreach ([3.0, 3.0, 2.0, 2.0, 2.0, 0.0] as $i => $score) {
        dashProduct(DASH_DAY, ['hot_score' => $score, 'votes_count' => $i % 2]);
    }
    Product::factory()->takenDown()->create(['launch_date' => DASH_DAY, 'hot_score' => 99.0]);

    // When both are asked for the same instant
    $ranking = app(TodayRanking::class);
    $feed = $ranking->ranking(positionNow());

    // Then every feed row has the position of its index
    foreach ($feed as $index => $product) {
        expect($ranking->positionOf(positionNow(), $product))->toBe($index + 1);
    }
    expect($feed)->toHaveCount(6);
});
