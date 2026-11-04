<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes auth_events older than `retention.auth_events_days`. The table has no immutability trigger and nothing
 * references it, so this is a plain chunked delete served by auth_events_created_at_index. Rows another session holds
 * are skipped (SKIP LOCKED) and caught next run. No user or product row is locked.
 */
class PurgeAuthEvents extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'auth_events';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        // Strictly older than the cutoff instant: a row exactly N days old is kept.
        $cutoff = $now->subDays($this->settings->days(SettingKey::RetentionAuthEventsDays))->format('Y-m-d H:i:s.uP');

        return DB::delete(
            'WITH picked AS MATERIALIZED (SELECT id FROM auth_events WHERE created_at < ? ORDER BY created_at LIMIT ? FOR UPDATE SKIP LOCKED) DELETE FROM auth_events USING picked WHERE auth_events.id = picked.id',
            [$cutoff, $limit],
        );
    }
}
