<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes bell rows older than `retention.notifications_days`, read or not (the age is `created_at`, served by
 * notifications_created_at_index). The rows hold ids, a message key and a product name only, nothing else references
 * them, and no user or product row is locked. Rows another session holds are skipped (SKIP LOCKED) and caught next run.
 */
class PurgeNotifications extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'notifications';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        // Strictly older than the cutoff instant: a row exactly N days old is kept.
        $cutoff = $now->subDays($this->settings->days(SettingKey::RetentionNotificationsDays))->format('Y-m-d H:i:s.uP');

        return DB::delete(
            'WITH picked AS MATERIALIZED (SELECT id FROM notifications WHERE created_at < ? ORDER BY created_at LIMIT ? FOR UPDATE SKIP LOCKED) DELETE FROM notifications USING picked WHERE notifications.id = picked.id',
            [$cutoff, $limit],
        );
    }
}
