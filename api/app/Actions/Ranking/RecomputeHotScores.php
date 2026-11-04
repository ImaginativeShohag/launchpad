<?php

namespace App\Actions\Ranking;

use App\Enums\ProductStatus;
use App\Events\RankingUpdated;
use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecomputeHotScores
{
    public const BATCH = 100;

    /** Scores closer than this (relative to the old score, at least absolute) are the same score: the column is a double precision. */
    private const TOLERANCE = 1e-9;

    private readonly int $batch;

    /** How many products failed to be re-scored in the last run, or batches that failed as a whole (they keep their old score until the next run). */
    public int $failed = 0;

    public function __construct(int $batch = self::BATCH, private readonly ?Settings $settings = null)
    {
        $this->batch = max(1, $batch);
    }

    /**
     * Re-scores every live product whose launch day is today (UTC). Rows are taken in id order with SKIP LOCKED
     * (a vote holds its product row for a moment; that product is simply picked up next minute) and each product is
     * scored in its own savepoint. The row locks are held for one batch only. The clock is read after the batch's
     * locks are held, so a batch that waited across midnight never scores the old day. Settings are read once per run.
     *
     * The last run of a day is at 23:59, so the final minute of votes is not scored here. That is intentional: the
     * permanent order is computed by FreezeDay from the buckets with currentHour 23, and today's feed is only a
     * live approximation.
     *
     * Counters of a batch are merged into the run's totals only after that batch committed. A batch that throws as a
     * whole (the locking select or the commit) is reported, counted as one failure, and ends the run; the next minute
     * retries it.
     *
     * @param  CarbonImmutable|null  $now  fixed instant for tests; the real clock (read per batch) when null
     * @return int how many products got a new score
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $hotScore = HotScore::fromSettings($this->settings ?? app(Settings::class));
        $changed = 0;
        $changedDay = null;
        $this->failed = 0;
        $afterId = '';

        do {
            try {
                /** @var array{count: int, lastId: string, changed: int, failed: int, day: string} $result */
                $result = DB::transaction(fn (): array => $this->scoreBatch($now, $hotScore, $afterId));
            } catch (Throwable $e) {
                report($e);
                $this->failed++;

                break;
            }

            $changed += $result['changed'];
            $this->failed += $result['failed'];
            if ($result['changed'] > 0) {
                $changedDay = $result['day'];
            }
            $afterId = $result['lastId'];
        } while ($result['count'] === $this->batch);

        if ($changedDay !== null) {
            try {
                RankingUpdated::dispatch($changedDay);
            } catch (Throwable $e) {
                // The scores are written; a failing listener must not fail the run.
                report($e);
            }
        }

        return $changed;
    }

    /** @return array{count: int, lastId: string, changed: int, failed: int, day: string} */
    private function scoreBatch(?CarbonImmutable $now, HotScore $hotScore, string $afterId): array
    {
        $candidateDay = ($now ?? CarbonImmutable::now('UTC'))->utc()->toDateString();

        $products = Product::query()
            ->where('status', ProductStatus::Live->value)
            ->where('launch_date', $candidateDay)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($this->batch)
            ->lock('for update skip locked')
            ->get();

        // The clock after the locks: a wait for them may have crossed midnight.
        $at = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $today = $at->toDateString();
        $result = ['count' => $products->count(), 'lastId' => (string) $products->last()?->getKey(), 'changed' => 0, 'failed' => 0, 'day' => $today];

        $due = $products->filter(fn (Product $product): bool => $product->launch_date?->toDateString() === $today);
        $buckets = app(BucketReader::class)->forDay(array_values($due->map(fn (Product $product): string => (string) $product->getKey())->all()), $at);

        foreach ($due as $product) {
            try {
                $updated = DB::transaction(fn (): bool => $this->rescore($product, $hotScore, $at, $buckets[$product->getKey()] ?? []));

                if ($updated) {
                    $result['changed']++;
                }
            } catch (Throwable $e) {
                $result['failed']++;
                report($e);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $votesByHour
     * @return bool whether a new score was written
     */
    private function rescore(Product $product, HotScore $hotScore, CarbonImmutable $at, array $votesByHour): bool
    {
        $score = $hotScore->score($votesByHour, $at->hour);
        $old = (float) $product->hot_score;

        if (abs($score - $old) <= self::TOLERANCE * max(1.0, abs($old))) {
            return false;
        }

        // Only hot_score is dirty and timestamps are off: a rank refresh is not an edit of the product.
        Product::withoutTimestamps(fn () => $product->forceFill(['hot_score' => $score])->save());

        return true;
    }
}
