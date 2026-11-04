<?php

namespace App\Actions\Ranking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SnapshotRanks
{
    /** Products ranked per run: a pathological day cannot produce an unbounded insert. */
    public const MAX = 500;

    public const CHUNK = 200;

    private readonly int $chunk;

    private readonly int $max;

    /** 1 when the last run failed as a whole (nothing was written; the next window retries), else 0. */
    public int $failed = 0;

    public function __construct(private readonly TodayRanking $ranking, int $chunk = self::CHUNK, int $max = self::MAX)
    {
        $this->chunk = max(1, $chunk);
        $this->max = max(1, $max);
    }

    /**
     * Records where today's products stand at this ten-minute mark. The clock is read once; captured_at is that
     * instant (UTC) floored to the mark, and the ranks, the day and the order come from TodayRanking with the same
     * instant, so the snapshot is exactly the live order at that moment (rank 1..n, score = the current hot_score).
     *
     * It reads committed data only and takes no lock on products: a product taken down a second after the read
     * keeps its row (readers filter with visible()). All chunks are written in ONE short transaction with
     * INSERT ... ON CONFLICT (product_id, captured_at) DO NOTHING, so a failing chunk leaves no half snapshot and a
     * second run in the same window inserts nothing. A failure is reported and counted in $failed.
     *
     * Limits to know: ranks above MAX are never stored. A snapshot of a product that is hidden later stays, so readers
     * must apply visible(). A skipped window is a permanent gap (charts must draw gaps). The 00:00 snapshot may be
     * empty or partial (the go-live job races it) and hot_score can be up to 60 s stale (the recompute runs
     * every minute).
     *
     * @param  CarbonImmutable|null  $now  fixed instant for tests; the real clock, read once per run, when null
     * @return int rows actually inserted
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $this->failed = 0;
        $at = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $mark = $at->setTime($at->hour, intdiv($at->minute, 10) * 10);
        $capturedAt = $mark->format('Y-m-d H:i:sP');

        try {
            $rows = [];
            foreach ($this->ranking->ranking($at, $this->max)->values() as $index => $product) {
                $rows[] = ['product_id' => $product->getKey(), 'captured_at' => $capturedAt, 'rank' => $index + 1, 'score' => (float) $product->hot_score];
            }

            return DB::transaction(function () use ($rows): int {
                $inserted = 0;
                foreach (array_chunk($rows, $this->chunk) as $chunk) {
                    $inserted += DB::table('rank_snapshots')->insertOrIgnore($chunk);
                }

                return $inserted;
            });
        } catch (Throwable $e) {
            report($e);
            $this->failed = 1;

            return 0;
        }
    }
}
