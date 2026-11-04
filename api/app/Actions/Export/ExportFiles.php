<?php

namespace App\Actions\Export;

use App\Models\Export;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The one place that knows the private `exports` disk and where an export's zip lives: `{product_id}/{export_id}.zip`
 * (ULIDs only, deterministic, so a retried build overwrites the same file). Resolved from the container so a test can bind
 * a subclass whose delete fails.
 */
class ExportFiles
{
    public const DISK = 'exports';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public static function path(Export $export): string
    {
        return $export->product_id.'/'.$export->id.'.zip';
    }

    /** Deletes a file. A missing file counts as gone. A failure is reported and answered with false; it never throws. */
    public function delete(string $path): bool
    {
        try {
            return self::disk()->delete($path);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
