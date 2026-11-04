<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Empties `ip_hash`, `device_hash` and `user_agent` of votes whose product launched more than
 * `retention.vote_hashes_days` days before today (UTC). The vote itself (status, product, user, voted_at, removed_at,
 * toggle_count, voiding columns, updated_at) is not touched, so counts, buckets and frozen rankings stay exact.
 *
 * Only rows of old launch days are updated, and only the vote rows are locked (`FOR NO KEY UPDATE OF v SKIP LOCKED`): never
 * a product or user row, and never a vote of the current day (CastVote locks the product, then the vote row of a
 * product launching today). Nothing reads these columns after the vote is written (CastVote only writes them), so a
 * NULL cannot break a feature. Idempotent: a row with all three empty is not selected again.
 */
class PurgeVoteHashes extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'votes';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        // A launch day exactly N days back is kept; the day before it is purged.
        $cutoffDay = $now->utc()->startOfDay()->subDays($this->settings->days(SettingKey::RetentionVoteHashesDays))->toDateString();

        return DB::update(<<<'SQL'
            WITH picked AS MATERIALIZED (
                SELECT v.id FROM votes v
                JOIN products p ON p.id = v.product_id
                WHERE p.launch_date < ?
                  AND (v.ip_hash IS NOT NULL OR v.device_hash IS NOT NULL OR v.user_agent IS NOT NULL)
                LIMIT ?
                FOR NO KEY UPDATE OF v SKIP LOCKED
            )
            UPDATE votes SET ip_hash = NULL, device_hash = NULL, user_agent = NULL
            FROM picked WHERE votes.id = picked.id
        SQL, [$cutoffDay, $limit]);
    }
}
