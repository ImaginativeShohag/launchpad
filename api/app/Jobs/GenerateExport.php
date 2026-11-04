<?php

namespace App\Jobs;

use App\Actions\Export\BuildExportFiles;
use App\Actions\Export\ExportFiles;
use App\Actions\Export\ExportTooLarge;
use App\Enums\ExportStatus;
use App\Enums\SettingKey;
use App\Events\ExportReady;
use App\Models\Export;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds the zip of an export. Three steps, only two of them under a lock: (1) a short transaction claims the row
 * (`processing`), (2) the files are built and the zip is written with no lock and no transaction, (3) a second short
 * transaction re-checks the row and finishes it. `ExportReady` is raised once, after that commit; a re-run of an export that
 * is already final does nothing. The unique lock outlives every try (3 x 75 + 70 < 600) and the timeout is below the queue's
 * `retry_after` (90). A killed worker or lost dispatch is caught by `launchpad:sweep-exports`. Production needs an async queue
 * and a running worker.
 */
class GenerateExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** The cap on the finished zip: 5 MB. */
    public const MAX_BYTES = 5242880;

    /** The four CSV files of the zip, under constant names (never user text). */
    public const ENTRIES = ['summary' => 'summary.csv', 'hourly' => 'hourly.csv', 'referrers_daily' => 'referrers_daily.csv', 'rank_timeline' => 'rank_timeline.csv'];

    public int $tries = 3;

    /** Below the queue's `retry_after` (90 s) so a hung run is not started twice. */
    public int $timeout = 75;

    /** Longer than every try and backoff together (3 x 75 + 10 + 60), so a duplicate dispatch is dropped for the whole life of the job. */
    public int $uniqueFor = 600;

    public function __construct(public readonly string $exportId) {}

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(BuildExportFiles $build, ExportFiles $files): void
    {
        $claimed = $this->claim();

        if ($claimed === null) {
            return;
        }

        [$userId, $productId] = $claimed;
        $path = $productId.'/'.$this->exportId.'.zip';
        $temp = null;

        try {
            // Outside any lock or transaction. One clock reading for every file.
            $asOf = CarbonImmutable::now('UTC');
            $export = Export::query()->findOrFail($this->exportId);
            $built = $build->handle($export, $asOf);
            // A cheap early guard on the text itself (a zip of it is never much larger than the text for random data).
            if (strlen($built['summary']) + strlen($built['hourly']) + strlen($built['referrers_daily']) + strlen($built['rank_timeline']) > self::MAX_BYTES) {
                throw new ExportTooLarge;
            }

            $temp = $this->zip($built);
            $size = (int) filesize($temp);

            if ($size > self::MAX_BYTES) {
                throw new ExportTooLarge;
            }

            $stream = fopen($temp, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The export zip could not be reopened.');
            }

            try {
                ExportFiles::disk()->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }
        } catch (ModelNotFoundException $e) {
            // The product was deleted mid-build: the export ends expired (as an owner who left does), not retried into a failure.
            if (! $this->expireIfGone()) {
                throw $e;
            }

            return;
        } catch (ExportTooLarge) {
            // Final: a retry would build the same file.
            $this->markFailed('too_large');

            return;
        } finally {
            if ($temp !== null && is_file($temp)) {
                unlink($temp);
            }
        }

        $outcome = $this->finish($path, $size, $built['row_counts']);

        if ($outcome === 'ready') {
            $this->raiseReady($userId, $productId);
        } elseif ($outcome === 'orphan') {
            // The row is gone, expired or failed meanwhile: the file just written belongs to nobody. A `ready` row
            // (a duplicate run) keeps its file, which is the same path.
            $files->delete($path);
        }
    }

    /** The last try failed (or a non-retried exception): the row stops being in flight. No exception text is stored. */
    public function failed(Throwable $e): void
    {
        report($e);

        $this->markFailed('generation_failed');
    }

    /**
     * Step 1: lock the row, leave if there is nothing to do, expire it when its owner or product is gone, else mark it
     * `processing`. A retry finds it `processing` and goes on.
     *
     * @return array{0: string, 1: string}|null user id and product id; null when there is nothing to build
     */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $row = Export::query()->whereKey($this->exportId)->lockForUpdate()->first();

            if ($row === null || ! $row->status->isInFlight()) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');

            if ($this->ownerGone($row)) {
                $row->forceFill(['status' => ExportStatus::Expired, 'updated_at' => $now])->save();

                return null;
            }

            $row->forceFill(['status' => ExportStatus::Processing, 'updated_at' => $now])->save();

            return [$row->user_id, $row->product_id];
        });
    }

    /**
     * Step 3: finish under the lock.
     *
     * @param  array<string, int>  $rowCounts
     * @return 'ready'|'orphan'|'kept'|'gone' orphan: delete the file; kept: leave it (the row is already ready)
     */
    private function finish(string $path, int $size, array $rowCounts): string
    {
        return DB::transaction(function () use ($path, $size, $rowCounts): string {
            $row = Export::query()->whereKey($this->exportId)->lockForUpdate()->first();

            if ($row === null) {
                return 'orphan';
            }

            if ($row->status === ExportStatus::Ready) {
                return 'kept';
            }

            if ($row->status !== ExportStatus::Processing) {
                return 'orphan';
            }

            $now = CarbonImmutable::now('UTC');

            if ($this->ownerGone($row)) {
                $row->forceFill(['status' => ExportStatus::Expired, 'updated_at' => $now])->save();

                return 'orphan';
            }

            $row->forceFill([
                'status' => ExportStatus::Ready, 'file_path' => $path, 'file_size' => $size, 'row_counts' => $rowCounts, 'error' => null,
                'completed_at' => $now, 'expires_at' => $now->addDays(app(Settings::class)->days(SettingKey::RetentionExportsDays)), 'updated_at' => $now,
            ])->save();

            return 'ready';
        });
    }

    /** Plain reads, no lock: a deleted owner or a deleted product ends the export. */
    private function ownerGone(Export $row): bool
    {
        return DB::table('users')->where('id', $row->user_id)->whereNotNull('deleted_at')->exists()
            || ! DB::table('products')->where('id', $row->product_id)->whereNull('deleted_at')->exists();
    }

    /** Marks a still-in-flight row `failed` with a short code and removes a file at the deterministic path. */
    private function markFailed(string $code): void
    {
        $path = DB::transaction(function () use ($code): ?string {
            $row = Export::query()->whereKey($this->exportId)->lockForUpdate()->first();

            if ($row === null || ! $row->status->isInFlight()) {
                return null;
            }

            $row->forceFill(['status' => ExportStatus::Failed, 'error' => $code, 'updated_at' => CarbonImmutable::now('UTC')])->save();

            return $row->product_id.'/'.$row->id.'.zip';
        });

        // After the commit; a failed delete is reported inside and never throws.
        if ($path !== null) {
            app(ExportFiles::class)->delete($path);
        }
    }

    /** Expires a still-processing row whose owner or product is gone; false when it is not gone (the error is real). */
    private function expireIfGone(): bool
    {
        return DB::transaction(function (): bool {
            $row = Export::query()->whereKey($this->exportId)->lockForUpdate()->first();

            if ($row === null || ! $this->ownerGone($row)) {
                return false;
            }

            if ($row->status->isInFlight()) {
                $row->forceFill(['status' => ExportStatus::Expired, 'updated_at' => CarbonImmutable::now('UTC')])->save();
            }

            return true;
        });
    }

    /** A raised event must not fail a finished export: a throwing listener is reported and nothing else. */
    private function raiseReady(string $userId, string $productId): void
    {
        try {
            ExportReady::dispatch($this->exportId, $productId, $userId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The four CSV strings as a zip in a temp file; the caller removes it.
     *
     * @param  array<string, mixed>  $built
     */
    private function zip(array $built): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'lpx');

        if ($temp === false) {
            throw new RuntimeException('No temporary file for the export zip.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The export zip could not be created.');
            }

            foreach (self::ENTRIES as $key => $name) {
                $zip->addFromString($name, (string) $built[$key]);
            }

            if ($zip->close() !== true) {
                throw new RuntimeException('The export zip could not be written.');
            }
        } catch (Throwable $e) {
            @unlink($temp);

            throw $e;
        }

        return $temp;
    }
}
