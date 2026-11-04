<?php

use App\Actions\Views\FlushViews;
use App\Models\Product;
use App\Support\ViewCounters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable(VIEW_NOON.' UTC'));
});

it('writes hourly buckets and referrer rows from a pending batch', function () {
    // Given views in two hours and three sources for one product
    $product = viewVisibleProduct();
    flushPending([
        "p|{$product->id}|".flushHour(10) => 3, "p|{$product->id}|".flushHour(11) => 2,
        "r|{$product->id}|".FLUSH_DAY.'|google' => 2, "r|{$product->id}|".FLUSH_DAY.'|x' => 2, "r|{$product->id}|".FLUSH_DAY.'|blog.example' => 1,
    ]);

    // When the flush runs
    $report = flushRun();

    // Then both tables hold the right numbers, buckets are created with votes 0, and the report says so
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 3], flushHour(11) => [0, 2]]);
    expect(flushReferrers($product))->toBe([FLUSH_DAY.'|blog.example' => 1, FLUSH_DAY.'|google' => 2, FLUSH_DAY.'|x' => 2]);
    expect([$report->batches, $report->buckets, $report->referrerRows, $report->skippedProducts, $report->failed])->toBe([1, 2, 3, 0, 0]);
});

it('adds views to an existing bucket without touching its votes, and a second flush adds again', function () {
    // Given a bucket created by a vote (7 votes) and 4 pending views in the same hour
    $product = viewVisibleProduct();
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => date('Y-m-d H:i:sO', flushHour(10)), 'votes' => 7, 'views' => 0]);
    flushPending(["p|{$product->id}|".flushHour(10) => 4, "r|{$product->id}|".FLUSH_DAY.'|direct' => 4]);

    // When the flush runs, and 2 more views are flushed afterwards
    flushRun();
    flushPending(["p|{$product->id}|".flushHour(10) => 2, "r|{$product->id}|".FLUSH_DAY.'|direct' => 2]);
    flushRun();

    // Then votes are untouched and views and visits accumulate
    expect(flushBuckets($product))->toBe([flushHour(10) => [7, 6]]);
    expect(flushReferrers($product))->toBe([FLUSH_DAY.'|direct' => 6]);
});

it('does nothing when nothing is pending and leaves no Redis key behind after a flush', function () {
    // Given an empty database
    // When the flush runs on nothing
    $empty = flushRun();

    // Then the report is all zero
    expect([$empty->batches, $empty->buckets, $empty->referrerRows, $empty->skippedProducts, $empty->failed])->toBe([0, 0, 0, 0, 0]);

    // And after a real flush, pending, every flushing key and the batch registration are gone
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 1, "r|{$product->id}|".FLUSH_DAY.'|direct' => 1]);
    flushRun();
    expect(flushRedisKeys())->toBe([])->and(app(ViewCounters::class)->batches())->toBe([]);
});

it('keeps the per product and day invariant over random beacons (views equal visits, total equals counted)', function () {
    // Given a small source cap so the fold is exercised, four products and a seeded random stream of 300 beacons in three flushes
    config(['launchpad.views.max_sources_per_day' => 6]);
    mt_srand(20261021);
    $products = collect(range(1, 4))->map(fn () => viewVisibleProduct());
    $sources = ['direct', 'other', 'launchpad', 'google', 'x', 'facebook', 'reddit', 'blog-a.example', 'blog-b.example', 'blog-c.example', 'blog-d.example', 'blog-e.example'];
    $counters = app(ViewCounters::class);
    $counted = 0;

    // When the beacons are recorded across three hours and flushed three times
    foreach (range(1, 300) as $i) {
        $at = CarbonImmutable::parse(FLUSH_DAY.' 10:00:00', 'UTC')->addMinutes(mt_rand(0, 179));
        $result = $counters->record($products[mt_rand(0, 3)]->id, 'device-'.mt_rand(1, 120), 'ip-'.mt_rand(1, 20), $at, $sources[mt_rand(0, 11)]);
        $counted += $result === ViewCounters::COUNTED ? 1 : 0;
        if ($i % 100 === 0) {
            flushRun();
        }
    }

    // Then per product and day the referrer sum equals the hourly view sum, the grand total is the counted number, and no day exceeds the cap
    $views = (int) DB::table('product_hourly_stats')->sum('views');
    foreach ($products as $product) {
        $hourly = (int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('views');
        $visits = (int) DB::table('product_referrers_daily')->where('product_id', $product->id)->sum('visits');
        expect($visits)->toBe($hourly);
        expect(DB::table('product_referrers_daily')->where('product_id', $product->id)->where('day', FLUSH_DAY)->count())->toBeLessThanOrEqual(6);
    }
    expect($views)->toBe($counted)->and($counted)->toBeGreaterThan(100)->and((int) DB::table('product_referrers_daily')->sum('visits'))->toBe($counted);
});

it('folds new sources into other when the day already holds 49 named sources', function () {
    // Given 49 stored named sources and a batch with five new ones
    $product = viewVisibleProduct();
    flushStoreSources($product, array_map(fn ($i) => "s{$i}.example", range(1, 49)));
    flushPendingSources($product, ['n1.example' => 2, 'n2.example' => 2, 'n3.example' => 2, 'n4.example' => 2, 'n5.example' => 2]);

    // When the flush runs
    flushRun();

    // Then all five fold into other, visits are conserved and the day holds 50 rows
    $rows = flushReferrers($product);
    expect($rows[FLUSH_DAY.'|other'])->toBe(10)->and(count($rows))->toBe(50)->and(array_sum($rows))->toBe(49 + 10);
    expect(array_filter(array_keys($rows), fn ($k) => str_contains($k, 'n1.example')))->toBe([]);
});

it('keeps the highest-visit new sources up to the free slots and folds the rest', function () {
    // Given 10 stored sources and 70 new ones with tied visit counts
    $product = viewVisibleProduct();
    flushStoreSources($product, array_map(fn ($i) => "stored{$i}.example", range(1, 10)));
    $new = [];
    foreach (range(1, 70) as $i) {
        $new[sprintf('new%02d.example', $i)] = ($i % 5) + 1;
    }
    flushPendingSources($product, $new);

    // When the flush runs
    flushRun();

    // Then 39 are kept (visits desc, then name) and 31 are folded into other with their visits
    $ordered = $new;
    uksort($ordered, fn ($a, $b) => [$new[$b], $a] <=> [$new[$a], $b]);
    $kept = array_slice($ordered, 0, 39, true);
    $folded = array_sum(array_slice($ordered, 39, null, true));
    $rows = flushReferrers($product);
    foreach ($kept as $source => $visits) {
        expect($rows[FLUSH_DAY.'|'.$source])->toBe($visits);
    }
    expect($rows[FLUSH_DAY.'|other'])->toBe($folded)->and(count($rows))->toBe(10 + 39 + 1)->and(array_sum($rows))->toBe(10 + array_sum($new));
});

it('never folds a source that is already stored, even when the day is full', function () {
    // Given a full day and a batch that adds to a stored source and brings a new one
    $product = viewVisibleProduct();
    flushStoreSources($product, array_map(fn ($i) => "s{$i}.example", range(1, 49)));
    flushPendingSources($product, ['s3.example' => 5, 'new.example' => 1]);

    // When the flush runs
    flushRun();

    // Then the stored source grows and only the new one folds
    $rows = flushReferrers($product);
    expect($rows[FLUSH_DAY.'|s3.example'])->toBe(6)->and($rows[FLUSH_DAY.'|other'])->toBe(1)->and(isset($rows[FLUSH_DAY.'|new.example']))->toBeFalse();
});

it('adds other to other without using a slot', function () {
    // Given 48 stored named sources (one slot free besides other) and a batch with other and one new source
    $product = viewVisibleProduct();
    flushStoreSources($product, array_map(fn ($i) => "s{$i}.example", range(1, 48)));
    flushPendingSources($product, ['other' => 4, 'new.example' => 1]);

    // When the flush runs
    flushRun();

    // Then the new source took the free slot and other holds its own four
    $rows = flushReferrers($product);
    expect($rows[FLUSH_DAY.'|new.example'])->toBe(1)->and($rows[FLUSH_DAY.'|other'])->toBe(4)->and(count($rows))->toBe(50);
});

it('applies the source cap per product and per day', function () {
    // Given a full day for one product
    $one = viewVisibleProduct();
    $two = viewVisibleProduct();
    flushStoreSources($one, array_map(fn ($i) => "s{$i}.example", range(1, 49)));
    $nextDay = '2026-10-22';
    flushPendingSources($one, ['a.example' => 1, 'b.example' => 1]);
    flushPendingSources($one, ['c.example' => 1, 'd.example' => 1], $nextDay);
    flushPendingSources($two, ['e.example' => 1]);

    // When the flush runs
    flushRun();

    // Then the full day folds, the next day and the other product start fresh
    expect(flushReferrers($one))->toHaveKey($nextDay.'|c.example')->toHaveKey($nextDay.'|d.example')->toHaveKey(FLUSH_DAY.'|other')->not->toHaveKey(FLUSH_DAY.'|a.example');
    expect(flushReferrers($two))->toBe([FLUSH_DAY.'|e.example' => 1]);
});

it('never holds more than 50 sources for a product and day over repeated flushes', function () {
    // Given one product and five flushes of 30 random new hosts each
    mt_srand(7);
    $product = viewVisibleProduct();

    // When the flushes run
    foreach (range(1, 5) as $round) {
        $batch = [];
        foreach (range(1, 30) as $i) {
            $batch['h'.mt_rand(1, 100000).'.example'] = mt_rand(1, 5);
        }
        flushPendingSources($product, $batch);
        flushRun();
    }

    // Then the day never exceeds 50 distinct sources and every visit is conserved
    expect(DB::table('product_referrers_daily')->where('product_id', $product->id)->count())->toBeLessThanOrEqual(50);
    expect((int) DB::table('product_referrers_daily')->sum('visits'))->toBe((int) DB::table('product_hourly_stats')->sum('views'));
});

it('rolls a failed batch back, keeps it for the next run and counts it once when the fault is gone', function () {
    // Given pending views and a database fault on the referrer upsert
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    Exceptions::fake();
    $broken = true;
    DB::listen(function ($query) use (&$broken) {
        if ($broken && str_starts_with($query->sql, 'INSERT INTO product_referrers_daily')) {
            throw new RuntimeException('injected fault');
        }
    });

    // When the flush runs
    $failed = flushRun();

    // Then nothing was written, the failure is counted and reported, and the batch key and registration remain
    expect($failed->failed)->toBe(1)->and($failed->batches)->toBe(0);
    expect(flushBuckets($product))->toBe([])->and(flushReferrers($product))->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'injected fault');
    expect(app(ViewCounters::class)->batches())->toHaveCount(1);

    // And once the fault is gone the next run writes the counts exactly once
    $broken = false;
    $ok = flushRun();
    expect($ok->failed)->toBe(0)->and($ok->batches)->toBe(1);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 3]])->and(flushReferrers($product))->toBe([FLUSH_DAY.'|google' => 3]);
    expect(flushRedisKeys())->toBe([]);
});

it('picks up an older leftover batch before the newest and applies both in one run', function () {
    // Given a batch rotated by a crashed run and a newer pending hash
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 2, "r|{$product->id}|".FLUSH_DAY.'|google' => 2]);
    $old = app(ViewCounters::class)->rotate();
    flushPending(["p|{$product->id}|".flushHour(10) => 5, "r|{$product->id}|".FLUSH_DAY.'|google' => 5]);

    // When the flush runs
    $order = [];
    DB::listen(function ($q) use (&$order) {
        if (str_starts_with($q->sql, 'INSERT INTO product_hourly_stats')) {
            $order[] = $q->bindings[2];
        }
    });
    $report = flushRun();

    // Then both applied, the older one first
    expect($old)->toBeInt()->and($report->batches)->toBe(2)->and($order)->toBe([2, 5]);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 7]]);
});

it('leaves views recorded during the write for the next batch', function () {
    // Given pending views, and a beacon arriving between the rotation and the database write
    $product = viewVisibleProduct();
    app(ViewCounters::class)->record($product->id, 'device-a', 'ip-1', CarbonImmutable::now('UTC'), 'google');
    $recorded = false;
    DB::listen(function ($q) use (&$recorded, $product) {
        if (! $recorded && str_starts_with($q->sql, 'INSERT INTO product_hourly_stats')) {
            $recorded = true;
            app(ViewCounters::class)->record($product->id, 'device-b', 'ip-2', CarbonImmutable::now('UTC'), 'google');
        }
    });

    // When the flush runs
    flushRun();

    // Then the late view is still pending and the next run counts it
    expect(array_sum(array_map('intval', viewPendingHash())))->toBe(2);
    flushRun();
    expect(flushReferrers($product))->toBe([FLUSH_DAY.'|google' => 2]);
});

it('skips a product that no longer exists and writes the others', function () {
    // Given a batch for a real product and a vanished one
    $product = viewVisibleProduct();
    $ghost = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    flushPending([
        "p|{$product->id}|".flushHour(10) => 1, "r|{$product->id}|".FLUSH_DAY.'|direct' => 1,
        "p|{$ghost}|".flushHour(10) => 4, "r|{$ghost}|".FLUSH_DAY.'|direct' => 4,
    ]);

    // When the flush runs
    $report = flushRun();

    // Then the ghost is skipped and counted, the real product is written, and no batch is left over
    expect($report->skippedProducts)->toBe(1)->and($report->failed)->toBe(0);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 1]])->and(flushBuckets($ghost))->toBe([]);
    expect(flushRedisKeys())->toBe([]);
});

it('still credits a taken-down and a soft-deleted product with the views counted while they were public', function () {
    // Given two products that are hidden by the time of the flush
    $down = Product::factory()->takenDown()->create(['launch_date' => FLUSH_DAY]);
    $deleted = viewVisibleProduct(['deleted_at' => now()]);
    foreach ([$down, $deleted] as $product) {
        flushPending(["p|{$product->id}|".flushHour(10) => 2, "r|{$product->id}|".FLUSH_DAY.'|direct' => 2]);
    }

    // When the flush runs
    $report = flushRun();

    // Then both receive their views and nothing is skipped
    expect($report->skippedProducts)->toBe(0)->and(flushBuckets($down))->toBe([flushHour(10) => [0, 2]])->and(flushBuckets($deleted))->toBe([flushHour(10) => [0, 2]]);
});

it('ignores garbage fields without failing the batch', function () {
    // Given a batch with unparsable fields next to a good one
    $product = viewVisibleProduct();
    flushPending([
        'x|y' => 3, 'p|not-a-ulid|123' => 1, "p|{$product->id}|abc" => 1, "p|{$product->id}" => 1, "r|{$product->id}|2026-13-45|google" => 1,
        "r|{$product->id}|".FLUSH_DAY.'|' => 1, "r|{$product->id}|".FLUSH_DAY.'|'.str_repeat('a', 300) => 1, "p|{$product->id}|".flushHour(10) => 0,
        "p|{$product->id}|".(flushHour(10) + 1234) => 2, "r|{$product->id}|".FLUSH_DAY.'|google' => 2,
    ]);

    // When the flush runs
    $report = flushRun();

    // Then only the good fields count (the epoch is floored to its hour) and the batch succeeds
    expect($report->failed)->toBe(0);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 2]])->and(flushReferrers($product))->toBe([FLUSH_DAY.'|google' => 2]);
    expect(flushRedisKeys())->toBe([]);
});

it('reports a drop that fails after the commit and leaves the rows committed (the documented double count window)', function () {
    // Given counters whose drop fails after the transaction committed
    Exceptions::fake();
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    $dropFails = true;
    $counters = new class(fn () => $dropFails) extends ViewCounters
    {
        public function __construct(private readonly Closure $fails) {}

        public function drop(int $n): void
        {
            if (($this->fails)()) {
                throw new RuntimeException('drop failed');
            }
            parent::drop($n);
        }
    };

    // When the flush runs
    $report = (new FlushViews($counters))->handle();

    // Then the rows are committed and the failure is counted and reported
    expect($report->failed)->toBe(1);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 3]]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'drop failed');

    // And the batch is still registered, so the next run would count that minute again: exactly the window the docs describe
    $dropFails = false;
    (new FlushViews($counters))->handle();
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 6]]);
});

it('drops a batch only after its transaction has closed', function () {
    // Given counters that record the transaction level at the moment of the drop
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    $levels = [];
    $counters = new class(function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    }) extends ViewCounters
    {
        public function __construct(private readonly Closure $onDrop) {}

        public function drop(int $n): void
        {
            ($this->onDrop)();
            parent::drop($n);
        }
    };
    $before = DB::transactionLevel();

    // When the flush runs
    (new FlushViews($counters))->handle();

    // Then the drop happened at the level the caller was on (the flush's own transaction had already committed)
    expect($levels)->toBe([$before]);
});

it('drops a registered batch whose key never existed', function () {
    // Given a registration without a key (a crash between SADD and RENAME)
    Redis::connection(config('launchpad.views.redis_connection'))->sadd('views:batches', 9);

    // When the flush runs
    $report = flushRun();

    // Then it is dropped quietly
    expect($report->failed)->toBe(0)->and(app(ViewCounters::class)->batches())->toBe([]);
});

it('skips a batch another runner has claimed, writes it once after the claim is released, and leaves no claim behind', function () {
    // Given a rotated batch claimed by another runner
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    $counters = app(ViewCounters::class);
    $n = $counters->rotate();
    expect($counters->claim($n))->toBeTrue()->and($counters->claim($n))->toBeFalse();

    // When a second runner flushes
    $skipped = flushRun();

    // Then it skips the batch (nothing written, not a failure) and the batch is still registered
    expect([$skipped->batches, $skipped->failed])->toBe([0, 0]);
    expect(flushBuckets($product))->toBe([])->and($counters->batches())->toBe([$n]);

    // And once the holder lets go the next run writes it exactly once and releases its own claim
    $counters->release($n);
    $ok = flushRun();
    expect($ok->batches)->toBe(1)->and(flushBuckets($product))->toBe([flushHour(10) => [0, 3]]);
    expect(flushRedisKeys())->toBe([]);
});

it('keeps an overlapping run out of a batch that is being written', function () {
    // Given pending views, and a second flush started while the first one is inside its transaction
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    $inner = null;
    DB::listen(function ($q) use (&$inner) {
        if ($inner === null && str_starts_with($q->sql, 'INSERT INTO product_hourly_stats')) {
            $inner = false;
            $inner = flushRun();
        }
    });

    // When the first flush runs
    $outer = flushRun();

    // Then the nested run skipped the batch and the views were counted once
    expect($inner->batches)->toBe(0)->and($outer->batches)->toBe(1);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 3]]);
});

it('lets a later run retry a batch whose runner crashed once the claim expires', function () {
    // Given a batch claimed by a runner that then died (the claim has a 10 minute lifetime)
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    $counters = app(ViewCounters::class);
    $n = $counters->rotate();
    $counters->claim($n);
    $redis = Redis::connection(config('launchpad.views.redis_connection'));
    expect($redis->ttl("views:claim:{$n}"))->toBeGreaterThan(590)->toBeLessThanOrEqual(ViewCounters::CLAIM_SECONDS);
    expect(flushRun()->batches)->toBe(0);

    // When the claim expires
    $redis->expire("views:claim:{$n}", 1);
    usleep(1_200_000);

    // Then the next run flushes the batch
    expect(flushRun()->batches)->toBe(1);
    expect(flushBuckets($product))->toBe([flushHour(10) => [0, 3]]);
});

it('releases the claim when a batch fails so the next run can retry', function () {
    // Given a batch that fails to write
    Exceptions::fake();
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    $broken = true;
    DB::listen(function ($query) use (&$broken) {
        if ($broken && str_starts_with($query->sql, 'INSERT INTO product_referrers_daily')) {
            throw new RuntimeException('injected fault');
        }
    });

    // When the run fails and the fault is removed
    expect(flushRun()->failed)->toBe(1);
    $broken = false;

    // Then the very next run retries it (no claim is left holding it)
    expect(flushRun()->batches)->toBe(1);
});
