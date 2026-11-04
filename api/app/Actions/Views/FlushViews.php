<?php

namespace App\Actions\Views;

use App\Support\ViewCounters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves the counted views from Redis into Postgres: one transaction per rotated batch, upserting `product_hourly_stats.views`
 * and `product_referrers_daily.visits`. Counts are added, never replaced, so a bucket made by a vote (or by an earlier
 * flush) only gains views.
 *
 * Lock order (see AGENTS.md): the batch's product rows are locked FOR KEY SHARE in id order BEFORE any bucket is inserted.
 * Inserting a bucket takes a key share on its product row anyway (the foreign key), after the tuple exists; if that wait came
 * second, a vote holding the product row FOR UPDATE and waiting on this transaction's uncommitted bucket would deadlock with
 * it. Taking the (non-conflicting with a counter update) share lock first removes the cycle. It never takes FOR UPDATE.
 *
 * Failure model: a throwing batch is rolled back, reported and counted, and its Redis key stays for the next run (it was never
 * committed, so it counts once). The key is dropped only after the commit; a drop that fails after the commit leaves a
 * window in which the next run counts that minute a second time (documented, approximate counters).
 */
final class FlushViews
{
    /** Rows per upsert statement (3 or 4 bindings each, far below the 65,535 bind limit). */
    private const UPSERT_ROWS = 1000;

    public function __construct(private readonly ViewCounters $counters, private readonly int $chunk = 1000) {}

    public function handle(): FlushReport
    {
        $report = new FlushReport;

        // A Redis failure here throws: nothing was moved, and the command reports it and exits non-zero.
        $this->counters->rotate();

        // Older leftovers of a crashed run first (ascending), then the one just rotated.
        foreach ($this->counters->batches() as $n) {
            try {
                $this->flushBatch($n, $report);
            } catch (Throwable $e) {
                report($e);
                $report->failed++;
            }
        }

        return $report;
    }

    private function flushBatch(int $n, FlushReport $report): void
    {
        // Another runner holds this batch (overlapping runs): leave it to them. A crashed holder's claim expires.
        if (! $this->counters->claim($n)) {
            return;
        }

        try {
            $this->writeBatch($n, $report);
        } finally {
            try {
                $this->counters->release($n);
            } catch (Throwable $e) {
                // The claim expires by itself; a failed release must not hide the batch's own outcome.
                report($e);
            }
        }
    }

    private function writeBatch(int $n, FlushReport $report): void
    {
        $fields = $this->counters->read($n);
        if ($fields === []) {
            $this->counters->drop($n);

            return;
        }

        [$hours, $refs] = $this->parse($fields);

        $done = DB::transaction(fn (): array => $this->write($hours, $refs));

        // Counters merge only after the commit.
        $report->batches++;
        $report->buckets += $done['buckets'];
        $report->referrerRows += $done['referrerRows'];
        $report->skippedProducts += $done['skipped'];

        try {
            $this->counters->drop($n);
        } catch (Throwable $e) {
            // The rows are committed; the next run would count this batch again. Log the batch id, never the contents.
            report($e);
            $report->failed++;
        }
    }

    /**
     * @param  array<string, int>  $fields
     * @return array{0: array<string, array<int, int>>, 1: array<string, array<string, array<string, int>>>} hours[product][epoch], refs[product][day][source]
     */
    private function parse(array $fields): array
    {
        $hours = [];
        $refs = [];

        foreach ($fields as $field => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            $parts = explode('|', (string) $field, 4);

            if ($parts[0] === 'p' && count($parts) === 3 && Str::isUlid($parts[1]) && ctype_digit($parts[2]) && strlen($parts[2]) <= 11) {
                $epoch = intdiv((int) $parts[2], 3600) * 3600;
                $hours[$parts[1]][$epoch] = ($hours[$parts[1]][$epoch] ?? 0) + $count;
            } elseif ($parts[0] === 'r' && count($parts) === 4 && Str::isUlid($parts[1]) && $this->isDay($parts[2]) && $parts[3] !== '' && strlen($parts[3]) <= 255) {
                $refs[$parts[1]][$parts[2]][$parts[3]] = ($refs[$parts[1]][$parts[2]][$parts[3]] ?? 0) + $count;
            }
        }

        return [$hours, $refs];
    }

    private function isDay(string $day): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $day, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @param  array<string, array<int, int>>  $hours
     * @param  array<string, array<string, array<string, int>>>  $refs
     * @return array{buckets: int, referrerRows: int, skipped: int}
     */
    private function write(array $hours, array $refs): array
    {
        // a. Share-lock the batch's product rows in id order, before anything is inserted.
        $ids = array_values(array_unique([...array_keys($hours), ...array_keys($refs)]));
        sort($ids, SORT_STRING);

        $existing = [];
        foreach (array_chunk($ids, max(1, $this->chunk)) as $chunk) {
            foreach (DB::table('products')->whereIn('id', $chunk)->orderBy('id')->lock('for key share')->pluck('id') as $id) {
                $existing[(string) $id] = true;
            }
        }

        $skipped = 0;
        foreach ($ids as $id) {
            if (! isset($existing[$id])) {
                $skipped++;
                unset($hours[$id], $refs[$id]);
            }
        }

        // b. The 50-source cap per product and day, then c. aggregate and sort.
        $refs = $this->foldSources($refs);

        $hourRows = [];
        foreach ($hours as $product => $byEpoch) {
            foreach ($byEpoch as $epoch => $views) {
                $hourRows[] = [$product, $epoch, $views];
            }
        }
        usort($hourRows, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $refRows = [];
        foreach ($refs as $product => $byDay) {
            foreach ($byDay as $day => $bySource) {
                foreach ($bySource as $source => $visits) {
                    $refRows[] = [$product, $day, (string) $source, $visits];
                }
            }
        }
        usort($refRows, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        // d. The upserts, in key order.
        foreach (array_chunk($hourRows, self::UPSERT_ROWS) as $rows) {
            DB::statement(
                'INSERT INTO product_hourly_stats (product_id, hour_start, votes, views) VALUES '
                .implode(', ', array_fill(0, count($rows), '(?, to_timestamp(?::bigint), 0, ?)'))
                .' ON CONFLICT (product_id, hour_start) DO UPDATE SET views = product_hourly_stats.views + EXCLUDED.views',
                array_merge(...$rows),
            );
        }

        foreach (array_chunk($refRows, self::UPSERT_ROWS) as $rows) {
            DB::statement(
                'INSERT INTO product_referrers_daily (product_id, day, source, visits) VALUES '
                .implode(', ', array_fill(0, count($rows), '(?, ?::date, ?, ?)'))
                .' ON CONFLICT (product_id, day, source) DO UPDATE SET visits = product_referrers_daily.visits + EXCLUDED.visits',
                array_merge(...$rows),
            );
        }

        return ['buckets' => count($hourRows), 'referrerRows' => count($refRows), 'skipped' => $skipped];
    }

    /**
     * At most `max_sources_per_day` sources per product and day: the named sources already stored count against the
     * limit, the batch's other new sources keep the free slots (most visits first, then name) and the rest fold into
     * `other`. A stored source and `other` itself are never folded.
     *
     * @param  array<string, array<string, array<string, int>>>  $refs
     * @return array<string, array<string, array<string, int>>>
     */
    private function foldSources(array $refs): array
    {
        $pairs = [];
        foreach ($refs as $product => $byDay) {
            foreach (array_keys($byDay) as $day) {
                $pairs[] = [$product, $day];
            }
        }
        if ($pairs === []) {
            return $refs;
        }

        // Stored sources per (product, day), read in chunks of the pair list.
        $stored = [];
        foreach (array_chunk($pairs, max(1, $this->chunk)) as $chunk) {
            $rows = DB::select(
                'SELECT product_id, day, source FROM product_referrers_daily WHERE (product_id, day) IN ('
                .implode(', ', array_fill(0, count($chunk), '(?, ?::date)')).')',
                array_merge(...$chunk),
            );
            foreach ($rows as $row) {
                $stored[$row->product_id][(string) $row->day][(string) $row->source] = true;
            }
        }

        $max = (int) config('launchpad.views.max_sources_per_day');

        foreach ($refs as $product => $byDay) {
            foreach ($byDay as $day => $bySource) {
                $known = $stored[$product][$day] ?? [];
                $storedNamed = count(array_filter(array_keys($known), fn ($s) => $s !== 'other'));
                $free = max(0, $max - 1 - $storedNamed);

                $fresh = array_filter($bySource, fn ($visits, $source) => $source !== 'other' && ! isset($known[$source]), ARRAY_FILTER_USE_BOTH);
                uksort($fresh, fn ($a, $b): int => [$fresh[$b], (string) $a] <=> [$fresh[$a], (string) $b]);

                $folded = array_slice($fresh, $free, null, true);
                foreach ($folded as $source => $visits) {
                    unset($bySource[$source]);
                    $bySource['other'] = ($bySource['other'] ?? 0) + $visits;
                }

                $refs[$product][$day] = $bySource;
            }
        }

        return $refs;
    }
}
