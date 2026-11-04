<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes rank_snapshots older than `retention.rank_snapshots_days` (by captured_at, served by
 * rank_snapshots_captured_at_index; the primary key leads with product_id). No personal data, no triggers, no FKs
 * pointing at the table, and no product row is locked: the snapshot job only inserts.
 */
class PurgeRankSnapshots extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'rank_snapshots';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        $cutoff = $now->subDays($this->settings->days(SettingKey::RetentionRankSnapshotsDays))->format('Y-m-d H:i:s.uP');

        return DB::delete(
            'WITH picked AS MATERIALIZED (SELECT product_id, captured_at FROM rank_snapshots WHERE captured_at < ? ORDER BY captured_at LIMIT ? FOR UPDATE SKIP LOCKED) DELETE FROM rank_snapshots USING picked WHERE rank_snapshots.product_id = picked.product_id AND rank_snapshots.captured_at = picked.captured_at',
            [$cutoff, $limit],
        );
    }
}
