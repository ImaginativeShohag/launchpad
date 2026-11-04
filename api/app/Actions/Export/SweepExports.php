<?php

namespace App\Actions\Export;

use App\Enums\ExportStatus;
use App\Jobs\GenerateExport;
use App\Models\Export;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The safety net of the export queue. A `queued` export older than ten minutes lost its dispatch (or the queue is stuck): it
 * is dispatched again (the job is unique, so a duplicate is dropped). A `processing` export untouched for ten minutes lost
 * its worker: it becomes `failed` with the code `stalled`, and the maker may start a new one. The clock is read once; a
 * failure on one row is counted and the loop goes on. Export rows only; no product or user row is touched.
 */
final class SweepExports
{
    public const AGE_MINUTES = 10;

    private const CHUNK = 200;

    /** @return array{redispatched: int, failed_stalled: int, failed: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $cutoff = $now->subMinutes(self::AGE_MINUTES)->format('Y-m-d H:i:s.uP');
        $result = ['redispatched' => 0, 'failed_stalled' => 0, 'failed' => 0];

        $after = '';
        do {
            $ids = DB::table('exports')->where('status', 'queued')->where('created_at', '<', $cutoff)->where('id', '>', $after)
                ->orderBy('id')->limit(self::CHUNK)->pluck('id');

            foreach ($ids as $id) {
                try {
                    GenerateExport::dispatch((string) $id);
                    $result['redispatched']++;
                } catch (Throwable $e) {
                    $result['failed']++;
                    report($e);
                }
            }

            $after = (string) $ids->last();
        } while ($ids->count() === self::CHUNK);

        $after = '';
        do {
            $ids = DB::table('exports')->where('status', 'processing')->where('updated_at', '<', $cutoff)->where('id', '>', $after)
                ->orderBy('id')->limit(self::CHUNK)->pluck('id');

            foreach ($ids as $id) {
                try {
                    $path = $this->failStalled((string) $id, $cutoff, $now);

                    if ($path !== null) {
                        $result['failed_stalled']++;
                        // After the commit. A killed worker may have written the zip before it died: nothing else would remove it.
                        app(ExportFiles::class)->delete($path);
                    }
                } catch (Throwable $e) {
                    $result['failed']++;
                    report($e);
                }
            }

            $after = (string) $ids->last();
        } while ($ids->count() === self::CHUNK);

        return $result;
    }

    /**
     * Locks the row (a row another session holds is skipped), re-checks status and age, and fails it.
     *
     * @return string|null the file key to clean up; null when the row was not failed
     */
    private function failStalled(string $id, string $cutoff, CarbonImmutable $now): ?string
    {
        return DB::transaction(function () use ($id, $cutoff, $now): ?string {
            $row = Export::query()->whereKey($id)->where('status', 'processing')->where('updated_at', '<', $cutoff)->lock('for update skip locked')->first();

            if ($row === null) {
                return null;
            }

            $row->forceFill(['status' => ExportStatus::Failed, 'error' => 'stalled', 'updated_at' => $now])->save();

            return ExportFiles::path($row);
        });
    }
}
