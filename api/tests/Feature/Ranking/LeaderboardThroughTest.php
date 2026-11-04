<?php

use App\Actions\Ranking\Leaderboard;
use App\Models\Product;
use Carbon\CarbonImmutable;

// Monday 2026-10-26 morning: the week that just ended is Mon 19 to Sun 25.
beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-26 07:00:00 UTC')));

function throughProduct(string $date, int $votes): Product
{
    return Product::factory()->live($date)->create(['votes_count' => $votes]);
}

it('ends the window on the given day, so a launch of the current day is left out', function () {
    // Given launches on Sunday, on Monday (today) and on the Sunday before the window began
    $sunday = throughProduct('2026-10-25', 3);
    $monday = throughProduct('2026-10-26', 99);
    $before = throughProduct('2026-10-18', 50);

    // When the week ending on Sunday is ranked with and without the upper bound
    $now = CarbonImmutable::parse('2026-10-25', 'UTC');
    $bounded = app(Leaderboard::class)->ranking('week', null, $now, 5, '2026-10-25')->pluck('id')->all();
    $unbounded = app(Leaderboard::class)->ranking('week', null, CarbonImmutable::parse('2026-10-26', 'UTC'), 5)->pluck('id')->all();

    // Then the bounded list holds Sunday's launch only, while the unbounded one (today's window) also has Monday's
    expect($bounded)->toBe([$sunday->id])->and($unbounded)->toBe([$monday->id, $sunday->id])->and($before->id)->not->toBeIn($bounded);
});

it('ranks the week by votes, newest launch day, then id, including ties', function () {
    // Given launches across the week, two tied on votes and day
    $tieA = throughProduct('2026-10-22', 5);
    $tieB = throughProduct('2026-10-22', 5);
    $newer = throughProduct('2026-10-24', 5);
    $top = throughProduct('2026-10-19', 8);
    $low = throughProduct('2026-10-25', 1);

    // When the week is ranked
    $ids = app(Leaderboard::class)->ranking('week', null, CarbonImmutable::parse('2026-10-25', 'UTC'), 5, '2026-10-25')->pluck('id')->all();

    // Then it is votes desc, newest launch day, id
    expect($ids)->toBe([$top->id, $newer->id, ...collect([$tieA, $tieB])->pluck('id')->sort()->values()->all(), $low->id]);
});

it('keeps every existing result when the bound is null', function () {
    // Given launches today and earlier this week
    $today = throughProduct('2026-10-26', 2);
    $earlier = throughProduct('2026-10-22', 4);

    // When ranked as before, and with a null bound explicitly
    $now = CarbonImmutable::parse('2026-10-26', 'UTC');
    $default = app(Leaderboard::class)->ranking('week', null, $now)->pluck('id')->all();
    $explicit = app(Leaderboard::class)->ranking('week', null, $now, Leaderboard::LIMIT, null)->pluck('id')->all();

    // Then both are identical and include today
    expect($default)->toBe([$earlier->id, $today->id])->and($explicit)->toBe($default);
});

it('still hides a taken-down product and a launch of a deleted maker inside a bounded window', function () {
    // Given a taken-down product and a normal one in the week
    $down = Product::factory()->takenDown()->create(['launch_date' => '2026-10-23', 'votes_count' => 100]);
    $kept = throughProduct('2026-10-23', 1);

    // When the week is ranked
    $ids = app(Leaderboard::class)->ranking('week', null, CarbonImmutable::parse('2026-10-25', 'UTC'), 5, '2026-10-25')->pluck('id')->all();

    // Then only the visible product is listed
    expect($ids)->toBe([$kept->id])->and($down->id)->not->toBeIn($ids);
});
