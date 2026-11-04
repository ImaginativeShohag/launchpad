<?php

namespace App\Actions\Export;

use App\Actions\Dashboard\RankHistory;
use App\Actions\Dashboard\Series;
use App\Actions\Dashboard\Summary;
use App\Actions\Dashboard\Window;
use App\Actions\Views\ReferrerSource;
use App\Enums\SettingKey;
use App\Models\Export;
use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The four CSV files of an export, built from the same Actions the dashboard reads (never from its cache), in one snapshot
 * and from one `asOf` instant. Read-only; takes no lock. Every string cell goes through CsvCell, so a product name or a
 * referrer host that starts with `=` is neutralised; numbers stay numbers.
 *
 * `hourly.csv` covers the launch day through `min(today, launch + 365 days)` (the first 366 days, at most 8,784 rows); the
 * summary says whether more days existed (`hourly_truncated`).
 */
class BuildExportFiles
{
    /** The days an hourly window may cover (the first 366 days from the launch). */
    public const HOURLY_DAYS = 366;

    public function __construct(private readonly Summary $summary, private readonly Series $series, private readonly RankHistory $rank) {}

    /** @return array{summary: string, hourly: string, referrers_daily: string, rank_timeline: string, row_counts: array<string, int>} */
    public function handle(Export $export, CarbonImmutable $asOf): array
    {
        $asOf = $asOf->utc();

        // Warm every setting the reads below may consult BEFORE the read-only snapshot: a cold cache store backed by the
        // database would otherwise try to write the cache inside it.
        $settings = app(Settings::class);
        foreach (SettingKey::cases() as $key) {
            $settings->get($key);
        }

        /** @var array{summary: string, hourly: string, referrers_daily: string, rank_timeline: string, row_counts: array<string, int>} */
        return ReadSnapshot::run(function () use ($export, $asOf): array {
            $product = Product::query()->findOrFail($export->product_id);

            [$summary, $summaryRows] = $this->summaryFile($product, $asOf);
            [$hourly, $hourlyRows] = $this->hourlyFile($product, $asOf);
            [$referrers, $referrerRows] = $this->referrersFile($product);
            [$timeline, $timelineRows] = $this->rankFile($product, $asOf);

            return [
                'summary' => $summary, 'hourly' => $hourly, 'referrers_daily' => $referrers, 'rank_timeline' => $timeline,
                'row_counts' => ['summary' => $summaryRows, 'hourly' => $hourlyRows, 'referrers_daily' => $referrerRows, 'rank_timeline' => $timelineRows],
            ];
        });
    }

    /** @return array{0: string, 1: int} */
    private function summaryFile(Product $product, CarbonImmutable $asOf): array
    {
        $facts = $this->summary->handle($product, $asOf);
        $rows = [
            ['product_name', $facts['name']], ['product_slug', $facts['slug']], ['launch_date', $facts['launch_date']],
            ['generated_at_utc', $asOf], ['views', $facts['views']], ['votes', $facts['votes']], ['comments', $facts['comments']],
            ['conversion', $facts['conversion']], ['final_rank', $facts['final_rank']], ['best_rank', $facts['best_rank']],
            ['current_rank', $facts['current_rank']], ['timeline_available', $facts['timeline_available']],
            ['hourly_truncated', $this->hourlyTruncated($product, $asOf)],
        ];

        return [CsvWriter::row(['key', 'value']).implode('', array_map(CsvWriter::row(...), $rows)), count($rows)];
    }

    /** @return array{0: string, 1: int} */
    private function hourlyFile(Product $product, CarbonImmutable $asOf): array
    {
        $out = CsvWriter::row(['hour_start_utc', 'votes', 'views', 'cumulative_votes', 'cumulative_views']);
        $count = 0;
        $range = $this->hourlyRange($product, $asOf);

        if ($range !== null) {
            [$from, $to] = $range;

            // One Series read per week, the Action's own cap, so memory stays flat; the cumulative totals continue across weeks.
            for ($start = $from; $start->lessThanOrEqualTo($to); $start = $start->addDays(Window::HOUR_DAYS)) {
                $end = $start->addDays(Window::HOUR_DAYS - 1);
                $end = $end->greaterThan($to) ? $to : $end;
                $window = Window::series($product, 'hour', $start->toDateString(), $end->toDateString(), $asOf);

                foreach ($this->series->handle($product, $window)['points'] as $point) {
                    $out .= CsvWriter::row([$point['t'], $point['votes'], $point['views'], $point['cumulative_votes'], $point['cumulative_views']]);
                    $count++;
                }
            }
        }

        return [$out, $count];
    }

    /** @return array{0: string, 1: int} */
    private function referrersFile(Product $product): array
    {
        $out = CsvWriter::row(['day', 'source', 'category', 'visits']);
        $count = 0;

        $rows = DB::cursor(
            'SELECT day, source, visits FROM product_referrers_daily WHERE product_id = ? ORDER BY day, visits DESC, source COLLATE "C"',
            [$product->getKey()],
        );

        foreach ($rows as $row) {
            $out .= CsvWriter::row([(string) $row->day, (string) $row->source, ReferrerSource::category((string) $row->source), (int) $row->visits]);
            $count++;
        }

        return [$out, $count];
    }

    /** @return array{0: string, 1: int} */
    private function rankFile(Product $product, CarbonImmutable $asOf): array
    {
        $out = CsvWriter::row(['captured_at_utc', 'rank', 'score']);
        $count = 0;

        foreach ($this->rank->handle($product, $asOf)['points'] as $point) {
            $out .= CsvWriter::row([$point['t'], $point['rank'], $point['score']]);
            $count++;
        }

        return [$out, $count];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null launch day and last day of the hourly file; null before the launch */
    private function hourlyRange(Product $product, CarbonImmutable $asOf): ?array
    {
        if ($product->launch_date === null) {
            return null;
        }

        $launch = CarbonImmutable::parse($product->launch_date->toDateString(), 'UTC')->startOfDay();
        $today = $asOf->startOfDay();

        if ($launch->greaterThan($today)) {
            return null;
        }

        $last = $launch->addDays(self::HOURLY_DAYS - 1);

        return [$launch, $last->lessThan($today) ? $last : $today];
    }

    /** True when the product is older than the 366 days the hourly file covers. */
    private function hourlyTruncated(Product $product, CarbonImmutable $asOf): bool
    {
        if ($product->launch_date === null) {
            return false;
        }

        $launch = CarbonImmutable::parse($product->launch_date->toDateString(), 'UTC')->startOfDay();

        return $launch->addDays(self::HOURLY_DAYS - 1)->lessThan($asOf->startOfDay());
    }
}
