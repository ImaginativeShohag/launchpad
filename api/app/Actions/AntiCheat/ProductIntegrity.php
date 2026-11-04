<?php

namespace App\Actions\AntiCheat;

use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * How clean the votes of one product look, computed on read from the vote rows (no stored column, so it is always
 * consistent with them): counted / active, where active = counted + flagged. Bands come from the two ratio settings.
 * Every read filters `votes` by `product_id` equality, so the index (product_id, voted_at) / the unique key serve it.
 */
final class ProductIntegrity
{
    public const GROUPS = 10;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{counted: int, flagged: int, voided: int, removed: int, active: int, integrity_ratio: float, band: string, open_signals: int, buckets: list<array{hour: string, votes: int, burst: bool}>, groups: array{device: list<array{fingerprint: string, accounts: int, votes: int}>, ip: list<array{fingerprint: string, accounts: int, votes: int}>}}
     */
    public function for(string $productId, CarbonImmutable $now): array
    {
        $counts = Sql::select(
            "SELECT COUNT(*) FILTER (WHERE status = 'counted' AND removed_at IS NULL) AS counted,
                    COUNT(*) FILTER (WHERE status = 'flagged' AND removed_at IS NULL) AS flagged,
                    COUNT(*) FILTER (WHERE status = 'voided') AS voided,
                    COUNT(*) FILTER (WHERE status <> 'voided' AND removed_at IS NOT NULL) AS removed
             FROM votes WHERE product_id = ?",
            [$productId],
        )[0];

        $counted = (int) $counts->counted;
        $flagged = (int) $counts->flagged;
        $active = $counted + $flagged;
        $ratio = $active === 0 ? 1.0 : round($counted / $active, 4);
        $bands = $this->settings->integrityBands();

        return [
            'counted' => $counted,
            'flagged' => $flagged,
            'voided' => (int) $counts->voided,
            'removed' => (int) $counts->removed,
            'active' => $active,
            'integrity_ratio' => $ratio,
            'band' => $ratio >= $bands['watch'] ? 'clean' : ($ratio >= $bands['alert'] ? 'watch' : 'alert'),
            'open_signals' => (int) Sql::select('SELECT COUNT(*) AS n FROM risk_signals WHERE product_id = ? AND reviewed_at IS NULL', [$productId])[0]->n,
            'buckets' => $this->buckets($productId, $now),
            'groups' => ['device' => $this->groups($productId, 'device_hash'), 'ip' => $this->groups($productId, 'ip_hash')],
        ];
    }

    /**
     * The last 24 hours (the current hour included), zero-filled, each with whether a product-level burst signal was
     * detected in that hour.
     *
     * @return list<array{hour: string, votes: int, burst: bool}>
     */
    private function buckets(string $productId, CarbonImmutable $now): array
    {
        $end = $now->utc()->startOfHour();
        $rows = Sql::select(
            "SELECT h.hour, COALESCE(s.votes, 0) AS votes,
                    EXISTS (SELECT 1 FROM risk_signals r WHERE r.product_id = ? AND r.kind = 'vote_burst' AND r.user_id IS NULL
                            AND r.detected_at >= h.hour AND r.detected_at < h.hour + interval '1 hour') AS burst
             FROM generate_series(?::timestamptz, ?::timestamptz, interval '1 hour') AS h(hour)
             LEFT JOIN product_hourly_stats s ON s.product_id = ? AND s.hour_start = h.hour
             ORDER BY h.hour",
            [$productId, Sql::ts($end->subHours(23)), Sql::ts($end), $productId],
        );

        return array_map(fn (object $row) => [
            'hour' => CarbonImmutable::parse($row->hour, 'UTC')->toIso8601String(),
            'votes' => (int) $row->votes,
            'burst' => (bool) $row->burst,
        ], $rows);
    }

    /**
     * Devices (or IPs) shared by at least two accounts on active votes: top 10, by accounts then votes, then the hash
     * itself for a deterministic order. Only the 8-character fingerprint leaves the database.
     *
     * @param  'device_hash'|'ip_hash'  $column
     * @return list<array{fingerprint: string, accounts: int, votes: int}>
     */
    private function groups(string $productId, string $column): array
    {
        $rows = Sql::select(
            'SELECT '.Sql::fingerprint($column).' AS fingerprint, COUNT(DISTINCT user_id) AS accounts, COUNT(*) AS votes FROM votes'
            .' WHERE product_id = ? AND '.Sql::activeVote('votes')." AND {$column} ~ '^[0-9a-f]{64}\$'"
            ." GROUP BY {$column} HAVING COUNT(DISTINCT user_id) >= 2 ORDER BY accounts DESC, votes DESC, {$column} LIMIT ".self::GROUPS,
            [$productId],
        );

        return array_map(fn (object $row) => ['fingerprint' => (string) $row->fingerprint, 'accounts' => (int) $row->accounts, 'votes' => (int) $row->votes], $rows);
    }
}
