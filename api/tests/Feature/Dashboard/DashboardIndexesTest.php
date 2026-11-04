<?php

use App\Actions\Dashboard\RankHistory;
use App\Actions\Dashboard\Referrers;
use App\Actions\Dashboard\Series;
use App\Actions\Dashboard\Summary;
use App\Actions\Dashboard\Window;
use App\Actions\Ranking\TodayRanking;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(dashNow());
    // Enough rows (several products, many hours) that the planner has statistics to choose from.
    $this->subject = dashProduct('2026-10-14');
    foreach ([$this->subject, dashProduct('2026-10-14'), dashProduct('2026-10-14')] as $n => $product) {
        $rows = [];
        for ($h = 0; $h < 8 * 24; $h++) {
            $rows[] = ['product_id' => $product->id, 'hour_start' => CarbonImmutable::parse('2026-10-14', 'UTC')->addHours($h)->format('Y-m-d H:i:sP'), 'votes' => 1, 'views' => 2];
        }
        DB::table('product_hourly_stats')->insert($rows);
        foreach (range(0, 7) as $d) {
            foreach (['x', 'google', 'direct', 'a.example.com'] as $source) {
                dashReferrer($product, CarbonImmutable::parse('2026-10-14')->addDays($d)->toDateString(), $source, 3);
            }
        }
        foreach (range(0, 143) as $i) {
            dashSnapshot($product, CarbonImmutable::parse('2026-10-21', 'UTC')->addMinutes($i * 10)->format('Y-m-d H:i:s'), 1 + $i % 7);
        }
        dashFreeze($product, $n + 1, '2026-10-14');
    }
    foreach (['product_hourly_stats', 'product_referrers_daily', 'rank_snapshots', 'daily_rankings'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
});

/**
 * The real statements an action ran against the stats tables, bindings substituted.
 *
 * @return list<string>
 */
function indexCapture(Closure $run): array
{
    $captured = [];
    DB::listen(function ($query) use (&$captured): void {
        if (preg_match('/^\s*select\b/i', $query->sql) === 1 && preg_match('/\b(product_hourly_stats|product_referrers_daily|rank_snapshots|daily_rankings)\b/', $query->sql) === 1) {
            $captured[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
        }
    });
    $run();

    return $captured;
}

function indexPlan(string $sql): string
{
    return collect(DB::select('EXPLAIN '.$sql))->pluck('QUERY PLAN')->implode("\n");
}

it('serves the hour series and its opening totals by the primary key, in order, without a sort', function () {
    // Given the real SQL of an hour series
    $window = Window::series($this->subject, 'hour', '2026-10-15', '2026-10-16', CarbonImmutable::now('UTC'));
    $sql = indexCapture(fn () => app(Series::class)->handle($this->subject, $window));

    // When each statement is planned
    // Then both walk product_hourly_stats_pkey and neither scans the table or sorts
    expect($sql)->toHaveCount(2);
    foreach ($sql as $statement) {
        $plan = indexPlan($statement);
        expect($plan)->toContain('product_hourly_stats_pkey')->not->toContain('Seq Scan')->not->toContain('Sort');
    }
});

it('serves the day series by the primary key range (the in-range grouping sort is the only sort)', function () {
    // Given the real SQL of a day series
    $window = Window::series($this->subject, 'day', '2026-10-15', '2026-10-20', CarbonImmutable::now('UTC'));
    $sql = indexCapture(fn () => app(Series::class)->handle($this->subject, $window));

    // When planned
    // Then the range is read through the primary key and never the whole table
    foreach ($sql as $statement) {
        expect(indexPlan($statement))->toContain('product_hourly_stats_pkey')->not->toContain('Seq Scan');
    }
});

it('serves the referrers by the primary key of product_referrers_daily', function () {
    // Given the real SQL
    $window = Window::range($this->subject, null, null, CarbonImmutable::now('UTC'));
    $sql = indexCapture(fn () => app(Referrers::class)->handle($this->subject, $window));

    // When planned
    // Then the primary key is used (the order by visits is an in-range sort of at most 50 rows per day)
    expect($sql)->toHaveCount(1)->and(indexPlan($sql[0]))->toContain('product_referrers_daily_pkey')->not->toContain('Seq Scan');
});

it('serves the rank points, the best rank and the final rank by primary keys', function () {
    // Given the real SQL of a rank read on the launch day (the subject's own launch day, 2026-10-14, with marks on 2026-10-21 only
    // is wrong for a range, so read a product whose launch is today)
    $today = dashProduct();
    foreach (range(0, 20) as $i) {
        dashSnapshot($today, CarbonImmutable::parse('2026-10-21', 'UTC')->addMinutes($i * 10)->format('Y-m-d H:i:s'), 1 + $i % 7);
    }
    // And a day with many frozen rows, so one lookup by (launch_date, product_id) is clearly cheaper than the day's whole rank range
    $maker = User::factory()->create();
    foreach (Product::factory()->count(60)->create(['maker_id' => $maker->id]) as $i => $other) {
        dashFreeze($other, 100 + $i, '2026-10-21');
    }
    dashFreeze($today, 1, '2026-10-21');
    foreach (['rank_snapshots', 'daily_rankings'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
    $sql = indexCapture(fn () => app(RankHistory::class)->handle($today));

    // When planned
    // Then each statement uses a primary key: daily_rankings by (launch_date, product_id), snapshots by (product_id, captured_at)
    expect($sql)->toHaveCount(3);
    $joined = collect($sql)->map(fn ($s) => indexPlan($s))->implode("\n---\n");
    expect($joined)->toContain('daily_rankings_pkey')->toContain('rank_snapshots_pkey')->not->toContain('Seq Scan');
    $points = collect($sql)->first(fn ($s) => str_contains($s, 'ORDER BY captured_at'));
    expect(indexPlan($points))->toContain('rank_snapshots_pkey')->not->toContain('Sort');
});

it('serves the summary\'s views total and top source by primary keys', function () {
    // Given the real SQL of a summary
    $sql = indexCapture(fn () => app(Summary::class)->handle($this->subject));

    // When planned
    // Then no statement scans a stats table
    expect($sql)->not->toBeEmpty();
    foreach ($sql as $statement) {
        expect(indexPlan($statement))->not->toContain('Seq Scan');
    }
    $views = collect($sql)->first(fn ($s) => str_contains($s, 'SUM(views)'));
    $top = collect($sql)->first(fn ($s) => str_contains($s, 'product_referrers_daily'));
    expect(indexPlan($views))->toContain('product_hourly_stats_pkey')->and(indexPlan($top))->toContain('product_referrers_daily_pkey');
});

it('serves the live position from the launch-date index of products, never a scan of the table', function () {
    // Given the real SQL of positionOf on a day with many launches
    $maker = User::factory()->create();
    foreach (Product::factory()->count(40)->live('2026-10-21')->create(['maker_id' => $maker->id]) as $i => $p) {
        $p->forceFill(['hot_score' => (float) $i])->save();
    }
    DB::statement('ANALYZE products');
    $product = Product::query()->where('launch_date', '2026-10-21')->firstOrFail();
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        if (str_contains($query->sql, 'FILTER (WHERE')) {
            $sql[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
        }
    });
    app(TodayRanking::class)->positionOf(CarbonImmutable::now('UTC'), $product);

    // When planned
    // Then products is read through an index on launch_date, not a sequential scan
    expect($sql)->toHaveCount(1)->and(indexPlan($sql[0]))->toContain('Index')->not->toContain('Seq Scan on products ');
});
