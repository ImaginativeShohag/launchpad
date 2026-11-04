<?php

namespace App\Actions\Ranking;

use App\Enums\ProductStatus;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class FreezeDay
{
    /** Days frozen per run; the next run (a minute later) continues. */
    public const MAX_DAYS = 31;

    /** A day is frozen only once it has been over this long: a cast judged on a slightly lagging host clock is over by then. */
    public const SETTLE_SECONDS = 60;

    /** Days looked back from the settled today; older gaps are a manual backfill (`launchpad:freeze-day --from=`). */
    public const WINDOW_DAYS = 31;

    public const INSERT_CHUNK = 1000;

    /** How many days failed in the last run (they stay as they were and are retried next run). */
    public int $failed = 0;

    public function __construct(
        private readonly FinalOrder $order,
        private readonly ?Settings $settings = null,
        private readonly int $insertChunk = self::INSERT_CHUNK,
    ) {}

    /**
     * Writes the permanent order of every finished launch day. A candidate day is one inside the window
     * (settled today - 31 days up to, not including, settled today; `$from` widens the lower bound for a manual
     * backfill) that has at least one live, non-deleted product without a daily_rankings row, oldest first, at most
     * MAX_DAYS per run. "Settled today" is the UTC date of (now - 60 s), the clock read once per run. Each day is its
     * own transaction:
     *
     *  1. its live products are locked FOR UPDATE in id order, WITHOUT skip locked: a cast in flight holds its
     *     product row, and the freeze must wait for it and then see its votes (global lock order step 1; the freeze
     *     holds only product rows, in id order, and takes no other lock, so it cannot deadlock a cast);
     *  2. under the lock it finds the live products that still lack a row (a concurrent worker may have frozen the
     *     day: then nothing is missing);
     *  3. it scores those products at hour 23 from their buckets, orders them by score, votes, id, appends rows
     *     after the day's last rank (1 for a day without rows) in chunked bulk inserts and stores the final score in
     *     products.hot_score.
     *
     * Append-only late freeze: existing rows are never updated or deleted (triggers guard the table). A product that
     * becomes live after its day was frozen (a suspended maker's launch released later, a restore, a lagging
     * PublishLaunches) is appended with the next rank at its next run, so frozen ranks never move but nobody stays
     * unranked. A product taken down before the freeze is not ranked; one taken down later keeps its row (reads
     * filter with visible()).
     *
     * The counter is merged only after the day's transaction committed. A failing day is reported and counted in
     * $failed; the loop goes on with the next day.
     *
     * @param  CarbonImmutable|null  $now  fixed instant for tests; the real clock, read once per run, when null
     * @param  string|null  $from  Y-m-d, lower bound of the window for a backfill
     * @return int how many days got new rows
     */
    public function handle(?CarbonImmutable $now = null, ?string $from = null): int
    {
        $this->failed = 0;
        $frozen = 0;
        $hotScore = HotScore::fromSettings($this->settings ?? app(Settings::class));
        $at = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $settledToday = $at->subSeconds(self::SETTLE_SECONDS)->startOfDay();
        $lowerBound = $from ?? $settledToday->subDays(self::WINDOW_DAYS)->toDateString();

        foreach ($this->candidateDays($lowerBound, $settledToday->toDateString()) as $day) {
            try {
                $done = DB::transaction(fn (): bool => $this->freeze($day, $hotScore, $at));
            } catch (Throwable $e) {
                report($e);
                $this->failed++;

                continue;
            }

            if ($done) {
                $frozen++;
            }
        }

        return $frozen;
    }

    /** @return list<string> Y-m-d, oldest first */
    private function candidateDays(string $lowerBound, string $settledToday): array
    {
        return array_values(DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', ProductStatus::Live->value)
            ->where('launch_date', '>=', $lowerBound)
            ->where('launch_date', '<', $settledToday)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('daily_rankings')
                ->whereColumn('daily_rankings.launch_date', 'products.launch_date')
                ->whereColumn('daily_rankings.product_id', 'products.id'))
            ->distinct()
            ->orderBy('launch_date')
            ->limit(self::MAX_DAYS)
            ->pluck('launch_date')
            ->map(fn ($date): string => substr((string) $date, 0, 10))
            ->all());
    }

    /** @return bool whether rows were appended by this call */
    private function freeze(string $day, HotScore $hotScore, CarbonImmutable $at): bool
    {
        $products = DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', ProductStatus::Live->value)
            ->where('launch_date', $day)
            ->orderBy('id')
            ->lock('for update')
            ->get(['id', 'votes_count']);

        $ranked = DB::table('daily_rankings')->where('launch_date', $day)->pluck('product_id')->flip();
        $missing = $products->reject(fn (object $row): bool => $ranked->has($row->id));

        if ($missing->isEmpty()) {
            return false;
        }

        $ordered = $this->order->handle(
            array_values($missing->map(fn (object $row): array => ['id' => (string) $row->id, 'votes_count' => (int) $row->votes_count])->all()),
            CarbonImmutable::parse($day, 'UTC'),
            $hotScore,
        );

        $rank = (int) DB::table('daily_rankings')->where('launch_date', $day)->max('rank');
        $frozenAt = $at->format('Y-m-d H:i:sP');

        $rows = [];
        foreach ($ordered as $item) {
            $rows[] = ['launch_date' => $day, 'product_id' => $item['id'], 'rank' => ++$rank, 'votes_count' => $item['votes_count'], 'score' => $item['score'], 'frozen_at' => $frozenAt];
        }

        foreach (array_chunk($rows, max(1, $this->insertChunk)) as $chunk) {
            DB::table('daily_rankings')->insert($chunk);
            $this->storeScores($chunk);
        }

        return true;
    }

    /** @param  list<array<string, mixed>>  $chunk */
    private function storeScores(array $chunk): void
    {
        $values = implode(',', array_fill(0, count($chunk), '(?::varchar, ?::double precision)'));
        $bindings = [];
        foreach ($chunk as $row) {
            array_push($bindings, $row['product_id'], $row['score']);
        }

        DB::update("UPDATE products SET hot_score = v.score FROM (VALUES {$values}) AS v(id, score) WHERE products.id = v.id", $bindings);
    }
}
