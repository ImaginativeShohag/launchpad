<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes digest run rows whose period is older than `retention.email_messages_days` (the digests' mail rows expire
 * with the same window). The table is tiny (a row per digest and period); a period exactly N days back is kept, the day
 * before it is purged. The key of a run is (kind, period_date), so the delete joins on both. Nothing references the table.
 */
class PurgeDigestRuns extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'digest_runs';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        $cutoffDay = $now->utc()->startOfDay()->subDays($this->settings->days(SettingKey::RetentionEmailMessagesDays))->toDateString();

        return DB::delete(
            'WITH picked AS MATERIALIZED (SELECT kind, period_date FROM digest_runs WHERE period_date < ? ORDER BY period_date LIMIT ? FOR UPDATE SKIP LOCKED) DELETE FROM digest_runs USING picked WHERE digest_runs.kind = picked.kind AND digest_runs.period_date = picked.period_date',
            [$cutoffDay, $limit],
        );
    }
}
