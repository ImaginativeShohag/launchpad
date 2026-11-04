<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\ProductBurstRule;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\SignalWriter;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function antiCheatR3a(Product $product, ?RuleConfig $config = null): array
{
    return antiCheatFindings(new ProductBurstRule, antiCheatProductScan($product), $config);
}

/** `$count` votes by fresh members, `$secondsAgo` seconds before the fixed clock. */
function antiCheatBurstVotes(Product $product, int $count, int $secondsAgo = 0): void
{
    for ($i = 0; $i < $count; $i++) {
        antiCheatVote(null, $product, ['voted_at' => antiCheatNow()->subSeconds($secondsAgo + $i % 5)]);
    }
}

/** A net-votes bucket of one of the three hours before the fixed clock's hour (1 = 11:00, 3 = 09:00). */
function antiCheatBucket(Product $product, int $hoursBack, int $votes): void
{
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => antiCheatNow()->startOfHour()->subHours($hoursBack), 'votes' => $votes, 'views' => 0]);
}

it('stays quiet below the minimum and fires at the minimum with no history (baseline 1)', function () {
    // Given a product with 29 votes in ten minutes, and another with 30
    antiCheatFreeze();
    $below = antiCheatLiveProduct();
    $at = antiCheatLiveProduct();
    antiCheatBurstVotes($below, 29, 60);
    antiCheatBurstVotes($at, 30, 60);

    // When the rule runs
    // Then 29 does not fire and 30 does (30 >= 4 x max(1, 0))
    expect(antiCheatR3a($below))->toBe([]);
    $findings = antiCheatR3a($at);
    expect($findings)->toHaveCount(1);
    expect($findings[0]->kind)->toBe(RiskSignalKind::VoteBurst)->and($findings[0]->weight)->toBe(0)->and($findings[0]->userId)->toBeNull()->and($findings[0]->productId)->toBe($at->id);
    expect($findings[0]->details)->toMatchArray(['rule' => 'vote_burst', 'scope' => 'product', 'vote_count' => 30, 'observed' => 30, 'threshold' => 30, 'window_minutes' => 10, 'baseline' => 1.0]);
});

it('compares with the baseline of the three previous full hours', function () {
    // Given a product with 120 votes in each of the three previous hours (20 per ten minutes)
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    foreach ([1, 2, 3] as $back) {
        antiCheatBucket($product, $back, 120);
    }

    // When 30 votes land in ten minutes, then 80
    antiCheatBurstVotes($product, 30, 60);
    $thirty = antiCheatR3a($product);
    antiCheatBurstVotes($product, 50, 60);
    $eighty = antiCheatR3a($product);

    // Then 30 is below 4 x 20 and 80 reaches it
    expect($thirty)->toBe([]);
    expect($eighty)->toHaveCount(1)->and($eighty[0]->details['baseline'])->toBe(20.0)->and($eighty[0]->details['vote_count'])->toBe(80);
});

it('clamps a negative baseline at zero and then at the floor of 1', function () {
    // Given buckets that voids pushed negative
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    foreach ([1, 2, 3] as $back) {
        antiCheatBucket($product, $back, -50);
    }
    antiCheatBurstVotes($product, 30, 60);

    // When the rule runs
    $findings = antiCheatR3a($product);

    // Then the baseline is the floor of 1 and 30 votes fire
    expect($findings)->toHaveCount(1)->and($findings[0]->details['baseline'])->toBe(1.0);
});

it('reads only the three full hours before the current one', function () {
    // Given a big bucket in the current hour and one four hours back
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatBucket($product, 4, 6000);
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => antiCheatNow()->startOfHour(), 'votes' => 6000, 'views' => 0]);
    antiCheatBurstVotes($product, 30, 60);

    // When the rule runs
    // Then neither bucket raises the baseline, so the burst still fires
    expect(antiCheatR3a($product))->toHaveCount(1);
});

it('measures the sliding window to the second', function () {
    // Given 29 votes at one instant a minute ago, plus one vote exactly ten minutes before them, and on another product one a second older
    antiCheatFreeze();
    $edge = antiCheatLiveProduct();
    $outside = antiCheatLiveProduct();
    $cluster = antiCheatNow()->subSeconds(60);
    foreach ([$edge, $outside] as $product) {
        for ($i = 0; $i < 29; $i++) {
            antiCheatVote(null, $product, ['voted_at' => $cluster]);
        }
    }
    antiCheatVote(null, $edge, ['voted_at' => $cluster->subMinutes(10)]);
    antiCheatVote(null, $outside, ['voted_at' => $cluster->subMinutes(10)->subSecond()]);

    // When the rule runs
    // Then the vote exactly one window before the last counts and the one a second older does not
    expect(antiCheatR3a($edge))->toHaveCount(1);
    expect(antiCheatR3a($outside))->toBe([]);
});

it('catches a burst that straddles two five-minute runs, once', function () {
    // Given 15 votes at 8 minutes ago and 15 at 2 minutes ago: each run alone sees only 15 of the last 10 minutes at one edge
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    for ($i = 0; $i < 15; $i++) {
        antiCheatVote(null, $product, ['voted_at' => antiCheatNow()->subMinutes(8)]);
        antiCheatVote(null, $product, ['voted_at' => antiCheatNow()->subMinutes(2)]);
    }

    // When the run at "now" sees them, and the run five minutes later sees the same burst again
    $writer = app(SignalWriter::class);
    $first = $writer->write(antiCheatR3a($product), antiCheatNow());
    $laterNow = antiCheatNow()->addMinutes(5);
    $later = $writer->write(antiCheatFindings(new ProductBurstRule, Scan::products($laterNow, [$product->id])), $laterNow);

    // Then the 30 votes inside one ten minute stretch are caught at once, and the repeat adds nothing (same hour, same key)
    expect($first)->toBe(['vote_burst' => 1])->and($later)->toBe([]);
});

it('skips a product in its first hour and averages over the hours that exist', function () {
    // Given a product that launched today and 30 votes in ten minutes at 00:30 UTC, then at 01:30 and at 02:30 with history
    $product = antiCheatLiveProduct();
    $config = antiCheatConfig();
    $at = fn (string $time) => CarbonImmutable::parse("2026-10-21 {$time}", 'UTC');
    test()->travelTo($at('00:30:00'));
    for ($i = 0; $i < 30; $i++) {
        antiCheatVote(null, $product, ['voted_at' => $at('00:29:00')]);
    }
    $scan = fn (CarbonImmutable $now) => Scan::products($now, [$product->id]);

    // When the rule runs in the first hour
    $first = antiCheatFindings(new ProductBurstRule, $scan($at('00:30:00')), $config);

    // Then there is no history, so nothing fires, however many votes
    expect($first)->toBe([]);

    // And with one full hour (00:00 bucket = 60 votes, baseline 10 per ten minutes) 40 votes reach 4 x 10
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => $at('00:00:00'), 'votes' => 60, 'views' => 0]);
    for ($i = 0; $i < 40; $i++) {
        antiCheatVote(null, $product, ['voted_at' => $at('01:29:00')]);
    }
    $one = antiCheatFindings(new ProductBurstRule, $scan($at('01:30:00')), $config);
    expect($one)->toHaveCount(1)->and($one[0]->details['baseline'])->toBe(10.0)->and($one[0]->details['vote_count'])->toBe(40);
});

it('divides by the one hour of history, not by three', function () {
    // Given 120 votes in the one existing hour (20 per ten minutes) and 70 votes in the window at 01:30
    $product = antiCheatLiveProduct();
    $config = antiCheatConfig();
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => CarbonImmutable::parse('2026-10-21 00:00:00', 'UTC'), 'votes' => 120, 'views' => 0]);
    for ($i = 0; $i < 70; $i++) {
        antiCheatVote(null, $product, ['voted_at' => CarbonImmutable::parse('2026-10-21 01:29:00', 'UTC')]);
    }
    $now = CarbonImmutable::parse('2026-10-21 01:30:00', 'UTC');

    // When the rule runs
    $findings = antiCheatFindings(new ProductBurstRule, Scan::products($now, [$product->id]), $config);

    // Then the baseline is 20 (not 6.67): 70 votes are below 4 x 20 = 80 and no burst is reported
    expect($findings)->toBe([]);
});

it('ignores withdrawn and voided votes and deleted members', function () {
    // Given 28 good votes and three that must not count
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatBurstVotes($product, 28, 60);
    antiCheatVote(null, $product, ['removed_at' => antiCheatNow()]);
    antiCheatVote(null, $product, ['status' => 'voided', 'voided_at' => antiCheatNow(), 'voided_reason' => 'x']);
    $gone = User::factory()->create(['deleted_at' => antiCheatNow()]);
    antiCheatVote($gone, $product);

    // When the rule runs
    // Then 28 is below the minimum
    expect(antiCheatR3a($product))->toBe([]);
});

it('caps the voter list at 50 and keeps the earliest voters', function () {
    // Given 60 votes in the window
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $first = null;
    for ($i = 0; $i < 60; $i++) {
        $vote = antiCheatVote(null, $product, ['voted_at' => antiCheatNow()->subSeconds(600 - $i * 5)]);
        $first ??= $vote->user_id;
    }

    // When the rule runs
    $details = antiCheatR3a($product)[0]->details;

    // Then 50 ids are kept, flagged truncated, and the earliest voter is among them
    expect($details['user_ids'])->toHaveCount(50)->and($details['truncated'])->toBeTrue()->and($details['user_ids'])->toContain($first)->and($details['vote_count'])->toBe(60);
});

it('writes once per product per hour and again in the next hour', function () {
    // Given a burst written at 12:00
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatBurstVotes($product, 30, 60);
    $writer = app(SignalWriter::class);
    $first = $writer->write(antiCheatR3a($product), antiCheatNow());

    // When the rule runs again later in the same hour, and in the next hour
    $same = $writer->write(antiCheatFindings(new ProductBurstRule, Scan::products(antiCheatNow()->addMinutes(2), [$product->id])), antiCheatNow()->addMinutes(2));
    antiCheatVote(null, $product, ['voted_at' => antiCheatNow()->addMinutes(65)]);
    $nextHourNow = antiCheatNow()->addMinutes(66);
    for ($i = 0; $i < 30; $i++) {
        antiCheatVote(null, $product, ['voted_at' => $nextHourNow->subSeconds(30 + $i)]);
    }
    $next = $writer->write(antiCheatFindings(new ProductBurstRule, Scan::products($nextHourNow, [$product->id])), $nextHourNow);

    // Then the same hour inserts nothing and the next hour inserts a new row
    expect($first)->toBe(['vote_burst' => 1])->and($same)->toBe([])->and($next)->toBe(['vote_burst' => 1]);
});

it('reads no hash column', function () {
    // Given a burst
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatBurstVotes($product, 30, 60);
    $config = antiCheatConfig();

    // When the rule runs
    $queries = antiCheatQueries(fn () => antiCheatFindings(new ProductBurstRule, antiCheatProductScan($product), $config));

    // Then no query mentions a hash column
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'ip_hash') || str_contains($sql, 'device_hash')))->toBeFalse();
});

it('honours the window, minimum and ratio settings', function () {
    // Given 12 votes within five minutes
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatBurstVotes($product, 12, 60);

    // When the minimum is lowered to 10 and the window narrowed to 5 minutes, then the ratio is raised to 20
    $fires = antiCheatR3a($product, antiCheatConfig(['anticheat.burst.min_votes' => 10, 'anticheat.burst.window_minutes' => 5]));
    $muted = antiCheatR3a($product, antiCheatConfig(['anticheat.burst.min_votes' => 10, 'anticheat.burst.window_minutes' => 5, 'anticheat.burst.ratio' => '20']));

    // Then 12 >= max(10, 4 x 1) fires, and 12 < 20 x 1 does not
    expect($fires)->toHaveCount(1)->and($fires[0]->details['window_minutes'])->toBe(5);
    expect($muted)->toBe([]);
});
