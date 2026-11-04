<?php

namespace App\Actions\Retention;

use App\Actions\Export\ExportFiles;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Expires and removes exports. A `ready` export past its `expires_at` (set from `retention.exports_days` when it finished),
 * or one of a deleted member, becomes `expired`; the zip of every expired row is deleted only AFTER the chunk's transaction
 * committed (a failed delete leaves the path on the row, and the next run finishes it, so no file is orphaned); `failed` and
 * `expired` rows without a file are deleted 30 days after their last change. Three bounded steps per chunk, rows picked with
 * SKIP LOCKED; the returned count is the rows touched, so a full step keeps the loop going. Export rows only: no product or
 * user row is ever locked.
 */
class PurgeExports extends RetentionPurge
{
    /** Days a finished-without-file row is kept before it is deleted. */
    public const KEEP_DAYS = 30;

    /** The window itself is stored per export (`expires_at`); the settings object keeps the constructor shape of the other purges. */
    public function __construct(Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS) // @phpstan-ignore constructor.unusedParameter
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'exports';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        // 1. Expire: ready and past expires_at, or ready and owned by a deleted member.
        $expired = DB::select(
            "WITH picked AS MATERIALIZED (
                SELECT e.id FROM exports e
                WHERE e.status = 'ready' AND (e.expires_at <= ? OR EXISTS (SELECT 1 FROM users u WHERE u.id = e.user_id AND u.deleted_at IS NOT NULL))
                ORDER BY e.id LIMIT ? FOR UPDATE OF e SKIP LOCKED
             )
             UPDATE exports SET status = 'expired', updated_at = ? FROM picked WHERE exports.id = picked.id
             RETURNING exports.id, exports.file_path",
            [$stamp, $limit, $stamp],
        );

        // 2. Files still on rows that are over (this chunk's step 1, and any delete that failed on an earlier run).
        $files = [];
        foreach ($expired as $row) {
            $files[(string) $row->id] = $row->file_path === null ? null : (string) $row->file_path;
        }
        $leftovers = DB::select(
            "SELECT id, file_path FROM exports WHERE status IN ('expired', 'failed') AND file_path IS NOT NULL ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED",
            [$limit],
        );
        $touched = count($expired);
        foreach ($leftovers as $row) {
            if (! array_key_exists((string) $row->id, $files)) {
                $touched++;
            }
            $files[(string) $row->id] = (string) $row->file_path;
        }
        $this->deleteAfterCommit(array_filter($files, fn (?string $path) => $path !== null));

        // 3. Rows that are over for good: no file left, untouched for 30 days (strictly older: a row exactly 30 days old stays).
        $cutoff = $now->subDays(self::KEEP_DAYS)->format('Y-m-d H:i:s.uP');
        $deleted = DB::delete(
            "WITH picked AS MATERIALIZED (
                SELECT id FROM exports WHERE status IN ('failed', 'expired') AND file_path IS NULL AND updated_at < ? ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED
             )
             DELETE FROM exports USING picked WHERE exports.id = picked.id",
            [$cutoff, $limit],
        );

        return $touched + $deleted;
    }

    /**
     * Registers the file deletions to run once the chunk's transaction has committed. A delete that works clears the row's
     * path; one that fails is reported inside ExportFiles and leaves the path for the next run.
     *
     * @param  array<string, string>  $paths  export id => file key
     */
    private function deleteAfterCommit(array $paths): void
    {
        foreach ($paths as $id => $path) {
            DB::afterCommit(function () use ($id, $path): void {
                try {
                    if (app(ExportFiles::class)->delete($path)) {
                        DB::table('exports')->where('id', $id)->where('file_path', $path)->update(['file_path' => null]);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    }
}
