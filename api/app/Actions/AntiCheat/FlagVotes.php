<?php

namespace App\Actions\AntiCheat;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marks the votes of high-risk members `flagged`, and takes the mark off again when the score falls.
 *
 * A flagged vote STILL COUNTS (votes_count, hourly buckets, hot score, rankings all treat `counted` and `flagged` alike),
 * so this pass changes a status column and nothing else: no counter, no bucket, no event, no broadcast, no audit row, and
 * no product or user row is locked. Only vote rows are touched, `FOR NO KEY UPDATE SKIP LOCKED` in a MATERIALIZED CTE (so
 * the planner cannot re-run the LIMITed subquery and touch more rows than the chunk), and the update is conditional on the
 * status it expects, so a vote voided or withdrawn meanwhile is never changed. A row locked by a vote action is skipped and
 * caught by the next run; CastVote/VoidVote hold a product row and wait for a vote row, this pass holds vote rows and waits
 * for nothing, so a vote row is never part of a cycle.
 *
 * Only recent launch days (yesterday and today, UTC) are touched: older days are frozen history. A deleted member's votes
 * are neither flagged nor unflagged (the deletion job voids them). Suspended members are flagged like anyone else.
 */
class FlagVotes
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(CarbonImmutable $now, int $chunk = 500, int $maxChunks = 200): FlagResult
    {
        $threshold = $this->settings->integer(SettingKey::AnticheatFlagRiskScore);
        $from = $now->utc()->subDay()->toDateString();
        $result = new FlagResult;

        $result->flagged = $this->pass('counted', 'flagged', '>=', $threshold, $from, $now, $chunk, $maxChunks, $result);
        $result->unflagged = $this->pass('flagged', 'counted', '<', $threshold, $from, $now, $chunk, $maxChunks, $result);

        return $result;
    }

    /**
     * One direction: repeat bounded chunks until a chunk comes back short. The cursor (last picked id) lives outside the
     * chunk's transaction; counters are merged after the chunk committed. A chunk that throws is reported and counted, and
     * ends this direction (nothing was picked, so there is no cursor to advance); the next run starts again.
     */
    private function pass(string $from, string $to, string $comparison, int $threshold, string $since, CarbonImmutable $now, int $chunk, int $maxChunks, FlagResult $result): int
    {
        $total = 0;
        $after = '';
        $chunk = max(1, $chunk);

        for ($i = 0; $i < $maxChunks; $i++) {
            try {
                [$picked, $updated, $last] = DB::transaction(fn (): array => $this->flagChunk($from, $to, $comparison, $threshold, $since, $now, $after, $chunk));
            } catch (Throwable $e) {
                $result->failedChunks++;
                report($e);

                break;
            }

            $total += $updated;

            if ($picked < $chunk || $last === null) {
                break;
            }

            $after = $last;
        }

        return $total;
    }

    /**
     * @return array{0: int, 1: int, 2: string|null} picked rows, updated rows, the last picked id
     */
    protected function flagChunk(string $from, string $to, string $comparison, int $threshold, string $since, CarbonImmutable $now, string $after, int $chunk): array
    {
        $row = Sql::select(
            "WITH picked AS MATERIALIZED (
                SELECT v.id FROM votes v
                JOIN users u ON u.id = v.user_id
                JOIN products p ON p.id = v.product_id
                WHERE v.status = ? AND v.removed_at IS NULL AND u.deleted_at IS NULL AND u.risk_score {$comparison} ?
                  AND p.launch_date >= ? AND v.id > ?
                ORDER BY v.id LIMIT {$chunk}
                FOR NO KEY UPDATE OF v SKIP LOCKED
            ), changed AS (
                -- The status and removed_at re-check below is defence in depth: `picked` already holds the rows locked, so it only
                -- matters if the lock were ever dropped (a vote voided or withdrawn meanwhile must never be flagged).
                UPDATE votes SET status = ?, updated_at = ?
                FROM picked
                WHERE votes.id = picked.id AND votes.status = ? AND votes.removed_at IS NULL
                RETURNING votes.id
            )
            SELECT (SELECT COUNT(*) FROM picked) AS picked, (SELECT COUNT(*) FROM changed) AS updated, (SELECT MAX(id) FROM picked) AS last_id",
            [$from, $threshold, $since, $after, $to, Sql::ts($now), $from],
        )[0];

        return [(int) $row->picked, (int) $row->updated, $row->last_id === null ? null : (string) $row->last_id];
    }
}
