<?php

use App\Actions\Dashboard\DashboardCache;
use App\Actions\Dashboard\Summary;
use App\Actions\Export\BuildExportFiles;
use App\Actions\Export\ExportFiles;
use App\Actions\Export\ReadSnapshot;
use App\Models\Export;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(dashNow());
});

/** A live product launching on the dashboard day with an export queued for it. */
function exportContentSetup(array $attributes = [], string $day = DASH_DAY): Export
{
    $product = dashProduct($day, $attributes);

    return exportFor($product, 'queued');
}

it('stores a zip with exactly the four documented entries and headers', function () {
    // Given a queued export of a live product
    $export = exportContentSetup();

    // When the job runs
    exportGenerate($export);

    // Then the zip has exactly four entries (no directories) with the documented header rows
    $files = exportZipFiles($export);
    expect(array_keys($files))->toBe(['summary.csv', 'hourly.csv', 'referrers_daily.csv', 'rank_timeline.csv']);
    expect(strtok($files['summary.csv'], "\r\n"))->toBe('key,value')
        ->and(strtok($files['hourly.csv'], "\r\n"))->toBe('hour_start_utc,votes,views,cumulative_votes,cumulative_views')
        ->and(strtok($files['referrers_daily.csv'], "\r\n"))->toBe('day,source,category,visits')
        ->and(strtok($files['rank_timeline.csv'], "\r\n"))->toBe('captured_at_utc,rank,score');
    expect($files['summary.csv'])->not->toStartWith("\xEF\xBB\xBF")->and(str_ends_with($files['summary.csv'], "\r\n"))->toBeTrue();
});

it('writes the same numbers as the dashboard reads', function () {
    // Given a product with buckets, referrers, snapshots and counters
    $export = exportContentSetup(['votes_count' => 7, 'comments_count' => 3]);
    $product = $export->product;
    dashBucket($product, '2026-10-21 03:00:00', 2, 10);
    dashBucket($product, '2026-10-21 09:00:00', 5, 30);
    dashReferrer($product, '2026-10-21', 'google', 25);
    dashReferrer($product, '2026-10-21', 'x', 15);
    dashSnapshot($product, '2026-10-21 08:00:00', 4, 1.25);
    dashSnapshot($product, '2026-10-21 08:10:00', 2, 2.5);

    // When the job runs
    exportGenerate($export);

    // Then the summary equals the Summary action, the hourly sums equal the buckets and the other files equal their tables
    $files = exportZipFiles($export);
    $facts = app(Summary::class)->handle($product->fresh(), CarbonImmutable::now('UTC'));
    $summary = exportSummaryMap($files['summary.csv']);
    expect($summary['product_name'])->toBe($facts['name'])->and($summary['product_slug'])->toBe($facts['slug'])->and($summary['launch_date'])->toBe(DASH_DAY)
        ->and($summary['views'])->toBe('40')->and($summary['votes'])->toBe('7')->and($summary['comments'])->toBe('3')
        ->and($summary['conversion'])->toBe((string) $facts['conversion'])->and($summary['best_rank'])->toBe('2')->and($summary['final_rank'])->toBe('')
        ->and($summary['timeline_available'])->toBe('true')->and($summary['hourly_truncated'])->toBe('false')
        ->and($summary['generated_at_utc'])->toBe('2026-10-21T12:00:00Z')->and(array_keys($summary))->toBe(['product_name', 'product_slug', 'launch_date', 'generated_at_utc', 'views', 'votes', 'comments', 'conversion', 'final_rank', 'best_rank', 'current_rank', 'timeline_available', 'hourly_truncated']);

    $hourly = exportCsvRows($files['hourly.csv']);
    expect($hourly)->toHaveCount(24)->and(array_sum(array_column($hourly, 1)))->toBe(7)->and(array_sum(array_column($hourly, 2)))->toBe(40);
    expect($hourly[3])->toBe(['2026-10-21T03:00:00Z', '2', '10', '2', '10'])->and($hourly[9])->toBe(['2026-10-21T09:00:00Z', '5', '30', '7', '40'])->and($hourly[23][3])->toBe('7');
    expect((int) $summary['views'])->toBe(array_sum(array_column($hourly, 2)));

    expect(exportCsvRows($files['referrers_daily.csv']))->toBe([['2026-10-21', 'google', 'search', '25'], ['2026-10-21', 'x', 'social', '15']]);
    expect(exportCsvRows($files['rank_timeline.csv']))->toBe([['2026-10-21T08:00:00Z', '4', '1.25'], ['2026-10-21T08:10:00Z', '2', '2.5']]);
});

it('writes one hourly row per hour from the launch day: 24, 72 and header only', function (string $launch, string $nowAt, int $rows) {
    // Given a product of that launch day, looked at on that day
    $this->travelTo(dashNow($nowAt));
    $export = exportContentSetup([], $launch);

    // When the job runs
    exportGenerate($export);

    // Then the hourly file has that many data rows, zero-filled
    expect(exportCsvRows(exportZipFiles($export)['hourly.csv']))->toHaveCount($rows);
})->with([
    'the launch day' => [DASH_DAY, DASH_DAY.' 12:00:00', 24],
    'three days old' => ['2026-10-19', '2026-10-21 12:00:00', 72],
    'a year and a day' => ['2025-10-21', '2026-10-21 12:00:00', 366 * 24],
]);

it('writes only headers for a product that has not launched', function () {
    // Given a scheduled product launching in the future
    $product = Product::factory()->scheduled('2026-10-30')->create();
    $export = exportFor($product, 'queued');

    // When the job runs
    exportGenerate($export);

    // Then every file holds its header (the summary its facts) and nothing else
    $files = exportZipFiles($export);
    expect(exportCsvRows($files['hourly.csv']))->toBe([])->and(exportCsvRows($files['referrers_daily.csv']))->toBe([])->and(exportCsvRows($files['rank_timeline.csv']))->toBe([]);
    expect(exportSummaryMap($files['summary.csv'])['views'])->toBe('0');
    expect($export->refresh()->row_counts)->toEqual(['summary' => 13, 'hourly' => 0, 'referrers_daily' => 0, 'rank_timeline' => 0]);
});

it('truncates the hourly file to the first 366 days and says so in the summary', function () {
    // Given a product launched 400 days ago, with a bucket on its first day and one after the cut
    $this->travelTo(dashNow('2026-10-21 12:00:00'));
    $launch = '2025-09-16';
    $export = exportContentSetup([], $launch);
    dashBucket($export->product, $launch.' 05:00:00', 1, 4);
    dashBucket($export->product, '2026-10-20 05:00:00', 9, 90);

    // When the job runs
    exportGenerate($export);

    // Then 8,784 hourly rows exist, the late bucket is outside, and the summary flags the truncation
    $files = exportZipFiles($export);
    $hourly = exportCsvRows($files['hourly.csv']);
    expect($hourly)->toHaveCount(8784)->and($hourly[0][0])->toBe('2025-09-16T00:00:00Z')->and($hourly[8783][0])->toBe('2026-09-16T23:00:00Z');
    expect($hourly[5][2])->toBe('4')->and(array_sum(array_column($hourly, 2)))->toBe(4);
    expect(exportSummaryMap($files['summary.csv'])['hourly_truncated'])->toBe('true')->and(exportSummaryMap($files['summary.csv'])['views'])->toBe('94');
});

it('does not flag truncation on the 366th day itself', function () {
    // Given a product whose 366th day is today
    $this->travelTo(dashNow('2026-10-21 12:00:00'));
    $export = exportContentSetup([], '2025-10-21');

    // When the job runs
    exportGenerate($export);

    // Then the file ends today and nothing was cut
    expect(exportSummaryMap(exportZipFiles($export)['summary.csv'])['hourly_truncated'])->toBe('false');
});

it('neutralises formula cells and quotes awkward text', function () {
    // Given a product named like a formula and referrer hosts that start with formula characters
    $export = exportContentSetup(['name' => "=cmd|' /C calc'!A0"]);
    dashReferrer($export->product, DASH_DAY, '-evil.example', 3);
    dashReferrer($export->product, DASH_DAY, '@evil.example', 2);
    dashReferrer($export->product, DASH_DAY, 'a,b.example', 1);

    // When the job runs
    exportGenerate($export);

    // Then the name and the hosts carry the apostrophe and the comma is quoted
    $files = exportZipFiles($export);
    expect(exportSummaryMap($files['summary.csv'])['product_name'])->toBe("'=cmd|' /C calc'!A0");
    $sources = array_column(exportCsvRows($files['referrers_daily.csv']), 1);
    expect($sources)->toBe(["'-evil.example", "'@evil.example", 'a,b.example']);
    expect($files['referrers_daily.csv'])->toContain('"a,b.example"');
});

it('quotes a product name with a comma, a quote and a newline', function () {
    // Given such a name
    $export = exportContentSetup(['name' => "Big, \"bold\"\nname"]);

    // When the job runs
    exportGenerate($export);

    // Then the raw file quotes it per RFC 4180 and a parser reads it back
    $summary = exportZipFiles($export)['summary.csv'];
    expect($summary)->toContain("product_name,\"Big, \"\"bold\"\"\nname\"\r\n");
});

it('stores row counts equal to the real data rows, the real size and the deterministic path', function () {
    // Given a product with two referrers and one snapshot
    $export = exportContentSetup();
    dashReferrer($export->product, DASH_DAY, 'google', 3);
    dashReferrer($export->product, DASH_DAY, 'x', 1);
    dashSnapshot($export->product, '2026-10-21 08:00:00', 3);

    // When the job runs
    exportGenerate($export);

    // Then the counts, the size and the path are the facts
    $export->refresh();
    $files = exportZipFiles($export);
    expect($export->row_counts)->toEqual(['summary' => 13, 'hourly' => 24, 'referrers_daily' => 2, 'rank_timeline' => 1]);
    expect($export->file_path)->toBe($export->product_id.'/'.$export->id.'.zip')->and($export->file_size)->toBe(Storage::disk('exports')->size($export->file_path));
    foreach (['hourly' => 'hourly.csv', 'referrers_daily' => 'referrers_daily.csv', 'rank_timeline' => 'rank_timeline.csv'] as $key => $name) {
        expect(count(exportCsvRows($files[$name])))->toBe($export->row_counts[$key]);
    }
});

it('never puts user text in an entry name or the stored path', function () {
    // Given hostile product text
    $export = exportContentSetup(['name' => '../../etc/passwd']);

    // When the job runs
    exportGenerate($export);

    // Then the entries and the path are constants and ids
    expect(array_keys(exportZipFiles($export)))->toBe(['summary.csv', 'hourly.csv', 'referrers_daily.csv', 'rank_timeline.csv']);
    expect($export->refresh()->file_path)->toMatch('~\A[0-9a-z]{26}/[0-9a-z]{26}\.zip\z~');
});

it('reads the whole export from one asOf instant and never from the dashboard cache', function () {
    // Given a cached summary that is out of date and a changed product counter
    config(['launchpad.dashboard.cache_seconds' => 30]);
    $export = exportContentSetup(['votes_count' => 1]);
    app(DashboardCache::class)->remember($export->product_id, 'summary', [], fn () => ['votes' => 999], $export->product->status->value);
    DB::table('products')->where('id', $export->product_id)->update(['votes_count' => 4]);

    // When the job runs
    exportGenerate($export);

    // Then the file holds the live number, not the cached one
    expect(exportSummaryMap(exportZipFiles($export)['summary.csv'])['votes'])->toBe('4');
});

it('builds the files through the one builder the job resolves from the container', function () {
    // Given the builder
    $export = exportContentSetup();

    // When it is called directly with a fixed instant
    $built = app(BuildExportFiles::class)->handle($export, CarbonImmutable::parse(DASH_DAY.' 12:00:00', 'UTC'));

    // Then it returns the four strings and the counts
    expect(array_keys($built))->toBe(['summary', 'hourly', 'referrers_daily', 'rank_timeline', 'row_counts']);
    expect(ExportFiles::path($export))->toBe($export->product_id.'/'.$export->id.'.zip');
});

it('runs a read plain under an open transaction and returns its value', function () {
    // Given the suite's own outer transaction (a snapshot statement would be an error in it)
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    // When a read runs through the snapshot helper
    $value = ReadSnapshot::run(fn () => DB::selectOne('show transaction_isolation')->transaction_isolation);

    // Then it did not try to change the isolation (the production path is checked by hand in psql/tinker)
    expect($value)->toBe('read committed');
});
