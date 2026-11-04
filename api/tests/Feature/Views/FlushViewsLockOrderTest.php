<?php

use App\Actions\Views\FlushViews;
use App\Support\ViewCounters;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable(VIEW_NOON.' UTC'));
});

it('share-locks the batch products in id order before the stored-sources read and the upserts, and never locks for update', function () {
    // Given five products with views in two hours and two sources each, flushed with a lock chunk of two
    $products = collect(range(1, 5))->map(fn () => viewVisibleProduct())->sortBy('id')->values();
    $fields = [];
    foreach ($products->reverse() as $product) {
        foreach ([10, 11] as $hour) {
            $fields["p|{$product->id}|".flushHour($hour)] = 2;
        }
        foreach (['google', 'x'] as $source) {
            $fields["r|{$product->id}|2026-10-21|{$source}"] = 2;
        }
    }
    flushPending($fields);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = [strtolower($q->sql), $q->bindings];
    });

    // When the flush runs
    (new FlushViews(app(ViewCounters::class), 2))->handle();

    // Then the first statements are the share locks, ascending across chunks of two
    $shareLocks = array_values(array_filter($queries, fn ($q) => str_contains($q[0], 'for key share')));
    expect($shareLocks)->toHaveCount(3);
    expect(array_slice(array_keys($queries), 0, 3))->toBe([0, 1, 2]);
    foreach ($shareLocks as $lock) {
        expect($lock[0])->toBeIn(['select "id" from "products" where "id" in (?, ?) order by "id" asc for key share', 'select "id" from "products" where "id" in (?) order by "id" asc for key share']);
    }
    expect(array_merge(...array_map(fn ($q) => $q[1], $shareLocks)))->toBe($products->pluck('id')->all());

    // And then come the stored-sources read and the two upserts, hourly first, each listing its rows in key order
    $statements = array_map(fn ($q) => $q[0], array_slice($queries, 3));
    foreach ([0, 1, 2] as $i) {
        expect($statements[$i])->toStartWith('select product_id, day, source from product_referrers_daily where (product_id, day) in');
    }
    expect($statements[3])->toStartWith('insert into product_hourly_stats')->and($statements[4])->toStartWith('insert into product_referrers_daily')->and($statements)->toHaveCount(5);
    $hourly = array_chunk($queries[6][1], 3);
    expect($hourly)->toHaveCount(10);
    $keys = array_map(fn ($r) => [$r[0], $r[1]], $hourly);
    $sorted = $keys;
    usort($sorted, fn ($a, $b) => $a <=> $b);
    expect($keys)->toBe($sorted);
    $refs = array_map(fn ($r) => [$r[0], $r[1], $r[2]], array_chunk($queries[7][1], 4));
    $sortedRefs = $refs;
    usort($sortedRefs, fn ($a, $b) => $a <=> $b);
    expect($refs)->toHaveCount(10)->and($refs)->toBe($sortedRefs);

    // And no statement locks for update or reads users
    foreach ($queries as [$sql]) {
        expect($sql)->not->toContain('for update')->not->toContain('"users"')->not->toContain('for share ');
    }
});
