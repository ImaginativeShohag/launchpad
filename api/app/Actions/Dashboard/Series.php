<?php

namespace App\Actions\Dashboard;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Votes and views over time from `product_hourly_stats`, by its primary key (product_id, hour_start): one range query for the
 * points and one for the totals before the window, so a later window continues the same cumulative curve. Gaps are zero-filled
 * here, never invented in SQL. Read-only; takes no lock.
 */
final class Series
{
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @return array{granularity: string, from: ?string, to: ?string, points: list<array<string, mixed>>} */
    public function handle(Product $product, Window $window): array
    {
        $from = $window->from;
        $to = $window->to;

        if ($window->empty || $from === null || $to === null || $product->launch_date === null) {
            return ['granularity' => $window->granularity, 'from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'points' => []];
        }

        $launch = CarbonImmutable::createFromFormat('!Y-m-d', $product->launch_date->toDateString(), 'UTC');
        $start = self::stamp($from);
        $end = self::stamp($to->addDay());

        // Totals between the launch and the window: the curve starts there. Hours before the launch are never counted.
        $before = DB::selectOne(
            'SELECT COALESCE(SUM(votes), 0) AS votes, COALESCE(SUM(views), 0) AS views FROM product_hourly_stats WHERE product_id = ? AND hour_start >= ? AND hour_start < ?',
            [$product->getKey(), self::stamp($launch ?: $from), $start],
        );
        $cumulativeVotes = (int) ($before->votes ?? 0);
        $cumulativeViews = (int) ($before->views ?? 0);

        if ($window->granularity === 'hour') {
            $rows = DB::select(
                'SELECT hour_start AS t, votes, views FROM product_hourly_stats WHERE product_id = ? AND hour_start >= ? AND hour_start < ? ORDER BY hour_start',
                [$product->getKey(), $start, $end],
            );
            $step = '1 hour';
        } else {
            $rows = DB::select(
                "SELECT date_trunc('day', hour_start, 'UTC') AS t, SUM(votes)::int AS votes, SUM(views)::int AS views FROM product_hourly_stats WHERE product_id = ? AND hour_start >= ? AND hour_start < ? GROUP BY 1 ORDER BY 1",
                [$product->getKey(), $start, $end],
            );
            $step = '1 day';
        }

        $byMark = [];
        foreach ($rows as $row) {
            $byMark[CarbonImmutable::parse($row->t)->utc()->format(self::FORMAT)] = [(int) $row->votes, (int) $row->views];
        }

        $points = [];
        $stop = $to->addDay();
        for ($mark = $from; $mark->lessThan($stop); $mark = $mark->modify('+'.$step)) {
            $t = $mark->format(self::FORMAT);
            [$votes, $views] = $byMark[$t] ?? [0, 0];
            $cumulativeVotes += $votes;
            $cumulativeViews += $views;
            $points[] = ['t' => $t, 'votes' => $votes, 'views' => $views, 'cumulative_votes' => $cumulativeVotes, 'cumulative_views' => $cumulativeViews];
        }

        return ['granularity' => $window->granularity, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'points' => $points];
    }

    private static function stamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:sP');
    }
}
