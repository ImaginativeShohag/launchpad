<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RiskSignalKey;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\Sql;
use App\Enums\RiskSignalKind;
use App\Enums\ScanScope;
use Carbon\CarbonImmutable;

/**
 * R3a (the product form of `vote_burst`): the active votes on one product in the last W minutes are at least `min_votes`
 * AND at least `ratio` times the baseline (the product's average votes per W minutes over the up to three previous full hourly
 * buckets since its launch day began, never below 1; a product in its first hour has no history and is skipped). The signal carries no member and weight 0: it feeds product integrity and the staff queue, it
 * never scores anyone (a viral launch must not punish its voters). It reads no hash.
 */
class ProductBurstRule implements Rule
{
    /** The scheduler's interval; the scan reaches back this far beyond the window. */
    private const RUN_INTERVAL_MINUTES = 5;

    public function scope(): ScanScope
    {
        return ScanScope::Products;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($scan->productIds === []) {
            return;
        }

        // A burst can straddle two five-minute runs: look back the window plus the run interval and slide the window over
        // those votes. Per product the densest stretch decides; a repeat in the next run hits the dedupe key.
        $scanFrom = $scan->now->subMinutes($config->burstWindowMinutes + self::RUN_INTERVAL_MINUTES);

        $peaks = Sql::select(
            'SELECT DISTINCT ON (t.product_id) t.product_id, t.c, t.peak_at FROM ('
            .'SELECT v.product_id, v.voted_at AS peak_at, COUNT(*) OVER w AS c FROM votes v JOIN users u ON u.id = v.user_id'
            .' WHERE v.product_id IN ('.Sql::placeholders($scan->productIds).') AND '.Sql::activeVote('v')
            .' AND v.voted_at >= ?::timestamptz AND u.deleted_at IS NULL'
            ." WINDOW w AS (PARTITION BY v.product_id ORDER BY v.voted_at RANGE BETWEEN (?::int * interval '1 minute') PRECEDING AND CURRENT ROW)"
            .') t WHERE t.c >= ? ORDER BY t.product_id, t.c DESC, t.peak_at',
            [...$scan->productIds, Sql::ts($scanFrom), $config->burstWindowMinutes, $config->burstMinVotes],
        );

        if ($peaks === []) {
            return;
        }

        $burstIds = array_map(fn (object $row) => $row->product_id, $peaks);

        // History: the full hours since the launch day began, at most the three before the current one. A product in its
        // first hour has none and is skipped; with one or two the average is taken over the hours that exist. Voids make a
        // bucket negative, so the sum is clamped at 0.
        $history = [];
        foreach (Sql::select(
            "WITH t AS (SELECT date_trunc('hour', ?::timestamptz, 'UTC') AS h0)"
            .' SELECT p.id AS product_id, h.hours,'
            .' COALESCE((SELECT GREATEST(SUM(s.votes), 0) FROM product_hourly_stats s WHERE s.product_id = p.id'
            ." AND s.hour_start >= t.h0 - (h.hours * interval '1 hour') AND s.hour_start < t.h0), 0) AS total"
            .' FROM products p CROSS JOIN t CROSS JOIN LATERAL ('
            ."SELECT LEAST(3, GREATEST(0, floor(extract(epoch FROM (t.h0 - (p.launch_date::timestamp AT TIME ZONE 'UTC'))) / 3600)))::int AS hours) h"
            .' WHERE p.id IN ('.Sql::placeholders($burstIds).')',
            [Sql::ts($scan->now), ...$burstIds],
        ) as $row) {
            $history[$row->product_id] = ['hours' => (int) $row->hours, 'total' => (int) $row->total];
        }

        // The first 51 voters of each peak window: 50 are kept, the 51st only proves the list was cut.
        $bindings = [];
        $values = [];
        foreach ($peaks as $row) {
            $values[] = '(?, ?::timestamptz, ?::timestamptz)';
            $peakAt = CarbonImmutable::parse($row->peak_at, 'UTC');
            array_push($bindings, $row->product_id, Sql::ts($peakAt->subMinutes($config->burstWindowMinutes)), Sql::ts($peakAt));
        }
        $voters = [];
        foreach (Sql::select(
            'SELECT product_id, user_id FROM (SELECT v.product_id, v.user_id, ROW_NUMBER() OVER (PARTITION BY v.product_id ORDER BY v.voted_at, v.id) AS rn'
            .' FROM votes v JOIN users u ON u.id = v.user_id JOIN (VALUES '.implode(', ', $values).') AS w(product_id, from_at, to_at) ON w.product_id = v.product_id'
            .' WHERE '.Sql::activeVote('v').' AND v.voted_at >= w.from_at AND v.voted_at <= w.to_at AND u.deleted_at IS NULL) t'
            .' WHERE rn <= '.(Finding::MAX_IDS + 1).' ORDER BY product_id, rn',
            $bindings,
        ) as $row) {
            $voters[$row->product_id][] = $row->user_id;
        }

        foreach ($peaks as $row) {
            $count = (int) $row->c;
            $hours = $history[$row->product_id]['hours'] ?? 0;

            if ($hours < 1) {
                continue;
            }

            $baseline = max(1.0, ($history[$row->product_id]['total'] ?? 0) / $hours * $config->burstWindowMinutes / 60);

            if ($count < $config->burstRatio * $baseline) {
                continue;
            }

            $windowStart = CarbonImmutable::parse($row->peak_at, 'UTC')->subMinutes($config->burstWindowMinutes);
            [$userIds, $capped] = Finding::capped($voters[$row->product_id] ?? [], sort: false);

            yield new Finding(
                RiskSignalKind::VoteBurst, null, $row->product_id, 0,
                Finding::details(RiskSignalKind::VoteBurst, $config->burstMinVotes, $count, $config->burstWindowMinutes, [
                    'scope' => 'product', 'vote_count' => $count, 'baseline' => round($baseline, 2),
                    'window_start' => $windowStart->toIso8601String(), 'user_ids' => $userIds,
                ], $capped),
                RiskSignalKey::burstProduct($row->product_id, $scan->now),
            );
        }
    }
}
