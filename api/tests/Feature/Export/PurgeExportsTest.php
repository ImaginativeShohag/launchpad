<?php

use App\Actions\Export\ExportFiles;
use App\Actions\Retention\PurgeExports;
use App\Enums\ExportStatus;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC'));
    Storage::fake('exports');
});

/** A ready export (with its file on the faked disk) expiring at $expiresAt. */
function exportPurgeReady(CarbonImmutable $expiresAt, ?Product $product = null): Export
{
    $export = exportFor($product ?? Product::factory()->live()->create(), 'ready', ['expires_at' => $expiresAt]);
    exportStoreFile($export);

    return $export;
}

function exportPurgeRun(int $chunk = 100, int $max = 100): int
{
    return retentionPurger(PurgeExports::class, $chunk, $max)->purge(retentionNow());
}

it('expires a ready export past expires_at and deletes its file only after the commit', function () {
    // Given a ready export that expired a second ago, and a files double that records the transaction level
    $export = exportPurgeReady(retentionNow()->subSecond());
    $path = $export->file_path;
    $baseline = DB::transactionLevel();
    $levels = new ArrayObject;
    app()->bind(ExportFiles::class, fn () => new class($levels) extends ExportFiles
    {
        public function __construct(private ArrayObject $levels) {}

        public function delete(string $path): bool
        {
            $this->levels[] = DB::transactionLevel();

            return parent::delete($path);
        }
    });

    // When the purge runs
    $purged = exportPurgeRun();

    // Then the row is expired, the file deleted at the baseline level, and the path cleared
    expect($purged)->toBe(1)->and($levels->getArrayCopy())->toBe([$baseline]);
    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Expired)->and($export->file_path)->toBeNull();
    Storage::disk('exports')->assertMissing($path);
});

it('keeps a ready export until exactly its expires_at', function (int $offsetSeconds, bool $expired) {
    // Given a ready export that expires at the instant plus the offset
    $export = exportPurgeReady(retentionNow()->addSeconds($offsetSeconds));

    // When the purge runs
    exportPurgeRun();

    // Then it is expired at or after expires_at and untouched before
    expect($export->refresh()->status)->toBe($expired ? ExportStatus::Expired : ExportStatus::Ready);
    $expired ? Storage::disk('exports')->assertMissing(ExportFiles::path($export)) : Storage::disk('exports')->assertExists(ExportFiles::path($export));
})->with([
    'one second before' => [1, false],
    'exactly at expires_at' => [0, true],
    'one second after' => [-1, true],
]);

it('expires the ready export of a deleted member whatever its expires_at', function () {
    // Given a ready export far from expiry whose owner was deleted (and one of a live owner)
    $gone = exportPurgeReady(retentionNow()->addDays(5));
    $kept = exportPurgeReady(retentionNow()->addDays(5));
    DB::table('users')->where('id', $gone->user_id)->update(['deleted_at' => retentionNow()]);

    // When the purge runs
    exportPurgeRun();

    // Then only the deleted member's export is expired and its file removed
    expect($gone->refresh()->status)->toBe(ExportStatus::Expired)->and($kept->refresh()->status)->toBe(ExportStatus::Ready);
    Storage::disk('exports')->assertMissing(ExportFiles::path($gone));
    Storage::disk('exports')->assertExists(ExportFiles::path($kept));
});

it('does not touch a queued or processing export of a deleted member', function (string $state) {
    // Given an in-flight export of a deleted member
    $export = exportFor(Product::factory()->live()->create(), $state);
    DB::table('users')->where('id', $export->user_id)->update(['deleted_at' => retentionNow()]);

    // When the purge runs
    $purged = exportPurgeRun();

    // Then it is left to the job (which expires it itself)
    expect($purged)->toBe(0)->and($export->refresh()->status->value)->toBe($state);
})->with(['queued', 'processing']);

it('finishes a failed file delete on the next run and leaves no orphan', function () {
    // Given an expired export whose first delete fails
    $export = exportPurgeReady(retentionNow()->subDay());
    $path = $export->file_path;
    $fail = true;
    app()->bind(ExportFiles::class, fn () => new class($fail) extends ExportFiles
    {
        public function __construct(private bool &$fail) {}

        public function delete(string $path): bool
        {
            return $this->fail ? false : parent::delete($path);
        }
    });

    // When the purge runs and the delete fails
    exportPurgeRun();

    // Then the row is expired but keeps its path and the file; the next run, with the disk working, finishes it
    expect($export->refresh()->status)->toBe(ExportStatus::Expired)->and($export->file_path)->toBe($path);
    Storage::disk('exports')->assertExists($path);
    $fail = false;
    app()->bind(ExportFiles::class, fn () => new ExportFiles);
    exportPurgeRun();
    expect($export->refresh()->file_path)->toBeNull();
    Storage::disk('exports')->assertMissing($path);
});

it('deletes failed and expired rows without a file once they are older than 30 days', function (string $state, int $days, bool $deleted) {
    // Given a row in that state, last touched that many days ago
    $export = exportFor(Product::factory()->live()->create(), $state, ['updated_at' => retentionNow()->subDays($days)]);

    // When the purge runs
    exportPurgeRun();

    // Then it is deleted after 30 days and kept up to and including 30
    expect(DB::table('exports')->where('id', $export->id)->exists())->toBe(! $deleted);
})->with([
    'failed 29 days' => ['failed', 29, false],
    'failed exactly 30 days' => ['failed', 30, false],
    'failed 31 days' => ['failed', 31, true],
    'expired 29 days' => ['expired', 29, false],
    'expired 31 days' => ['expired', 31, true],
]);

it('cleans a file left on an old failed row first, then deletes the row on a later run', function () {
    // Given a failed export 31 days old that still holds a path and a file
    $export = exportFor(Product::factory()->live()->create(), 'failed', ['updated_at' => retentionNow()->subDays(31), 'file_path' => 'x/leftover.zip']);
    Storage::disk('exports')->put('x/leftover.zip', 'old');

    // When the purge runs twice
    exportPurgeRun();
    $afterFirst = DB::table('exports')->where('id', $export->id)->first();
    exportPurgeRun();

    // Then the first run removed the file and cleared the path, the second deleted the row
    expect($afterFirst)->not->toBeNull()->and($afterFirst->file_path)->toBeNull();
    Storage::disk('exports')->assertMissing('x/leftover.zip');
    expect(DB::table('exports')->where('id', $export->id)->exists())->toBeFalse();
});

it('is idempotent and ends: a second run touches nothing', function () {
    // Given five expired exports and a chunk of two
    foreach (range(1, 5) as $_) {
        exportPurgeReady(retentionNow()->subHour());
    }

    // When the purge runs, then again
    $first = exportPurgeRun(2, 20);
    $second = exportPurgeRun(2, 20);

    // Then all five were expired in the first run and nothing remains for the second
    expect($first)->toBe(5)->and($second)->toBe(0);
    expect(Storage::disk('exports')->allFiles())->toBe([]);
});

it('keeps the count of committed chunks when a later chunk fails', function () {
    // Given three expired exports, chunk 1, and a subclass whose second chunk fails
    foreach (range(1, 3) as $_) {
        exportPurgeReady(retentionNow()->subHour());
    }
    $purge = new class(app(Settings::class), 1, 10) extends PurgeExports
    {
        private int $calls = 0;

        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            if (++$this->calls === 2) {
                DB::select('select 1/0');
            }

            return parent::purgeChunk($now, $limit);
        }
    };

    // When it runs
    try {
        $purge->purge(retentionNow());
    } catch (QueryException) {
    }

    // Then the first chunk's rows stay counted
    expect($purge->purgedSoFar)->toBe(1);
});

it('moves only new exports when the retention window changes', function () {
    // Given an old export that finished under the 7 day window, then the window set to 2 days
    $old = exportFor(Product::factory()->live()->create(), 'queued');
    exportGenerate($old);
    $oldExpiry = $old->refresh()->expires_at->clone();
    app(Settings::class)->set(SettingKey::RetentionExportsDays, 2, User::factory()->role(Role::Admin)->create());
    $new = exportFor(Product::factory()->live()->create(), 'queued');
    exportGenerate($new);

    // When both are read
    // Then the old export keeps its stored expiry and the new one got 2 days
    expect($old->refresh()->expires_at->equalTo($oldExpiry))->toBeTrue();
    expect($new->refresh()->expires_at->diffInDays($new->completed_at, true))->toBe(2.0);
});

it('takes no product or user lock', function () {
    // Given an expired export and an old failed one
    exportPurgeReady(retentionNow()->subHour());
    exportFor(Product::factory()->live()->create(), 'failed', ['updated_at' => retentionNow()->subDays(60)]);

    // When the purge runs
    $locks = exportLockingStatements(fn () => exportPurgeRun());

    // Then every locking statement is on exports
    expect($locks)->not->toBeEmpty();
    foreach ($locks as $sql) {
        expect($sql)->toContain('exports')->not->toContain('from "products"');
        // A lock clause names no table other than the export alias (the deleted-owner check reads users without locking).
        preg_match_all('/for (?:update|share|key share)(?: of (\w+))?/', $sql, $m);
        expect(array_filter($m[1], fn (string $target) => $target !== '' && $target !== 'e'))->toBe([]);
    }
});
