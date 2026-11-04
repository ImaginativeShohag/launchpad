<?php

namespace App\Actions\Export;

use App\Enums\ExportStatus;
use App\Exceptions\ExportRuleViolation;
use App\Models\Export;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Decides whether a maker may download an export now, and under which name. Ownership comes first (a foreign, other-product
 * or unknown export is the uniform 404 whatever its state), then the state: not finished is 409, expired (by status, by
 * `expires_at` even before the purge ran, or because the file is gone) is 410. A taken-down product still downloads an
 * existing export: it is the maker's own data. Reads only; takes no lock.
 */
final class DownloadExport
{
    public function __construct(private readonly ExportAccess $access) {}

    /**
     * @return array{export: Export, path: string, filename: string, stream: resource}
     *
     * @throws ModelNotFoundException
     * @throws ExportRuleViolation
     */
    public function handle(User $actor, string $productId, string $exportId, ?CarbonImmutable $now = null): array
    {
        $export = $this->access->export($actor, $productId, $exportId);
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();

        if ($export->status === ExportStatus::Expired) {
            throw ExportRuleViolation::expired();
        }

        if ($export->status !== ExportStatus::Ready) {
            throw ExportRuleViolation::notReady();
        }

        $path = $export->file_path;

        if ($export->expires_at === null || $export->expires_at->lessThanOrEqualTo($now) || $path === null) {
            throw ExportRuleViolation::expired();
        }

        // Opened here, so a file deleted since the checks above (the purge, a replaced export) is a 410 and never a failed stream.
        $stream = $this->open($path);

        $slug = (string) DB::table('products')->where('id', $export->product_id)->value('slug');
        $day = ($export->completed_at ?? $export->created_at)->clone()->utc()->format('Ymd');

        return ['export' => $export, 'path' => $path, 'filename' => 'launchpad-'.self::safeSlug($slug).'-'.$day.'.zip', 'stream' => $stream];
    }

    /** The slug lower-cased and reduced to [a-z0-9-], repeats collapsed, edges trimmed; `product` when nothing is left. */
    public static function safeSlug(string $slug): string
    {
        $safe = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slug)), '-');

        return $safe === '' ? 'product' : $safe;
    }

    /**
     * @return resource
     *
     * @throws ExportRuleViolation expired: the file is gone or cannot be read
     */
    private function open(string $path)
    {
        try {
            $stream = ExportFiles::disk()->readStream($path);
        } catch (Throwable $e) {
            report($e);

            throw ExportRuleViolation::expired();
        }

        if (! is_resource($stream)) {
            throw ExportRuleViolation::expired();
        }

        return $stream;
    }
}
