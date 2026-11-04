<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sets `ip_hash` and `user_agent` of audit_logs rows older than `retention.audit_network_days` to NULL. The row (who,
 * what, when, subject, meta) stays for ever. The table is append-only by trigger; the migration that created
 * `audit_logs_unpurged_network_index` lets an UPDATE through only when it changes nothing but these two columns, sets
 * both to NULL, and the transaction set the transaction-local flag below. The flag is set for this one statement and
 * switched off again straight after it, so a nested caller's later statements are never covered.
 *
 * The idempotency WHERE matches the partial index predicate textually (a purged row is not selected again). Only the
 * audit rows themselves are locked (SKIP LOCKED): never a user or product row.
 */
class PurgeAuditNetwork extends RetentionPurge
{
    public const FLAG = 'launchpad.audit_purge';

    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'audit_logs';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        // Strictly older than the cutoff instant: a row exactly N days old is kept.
        $cutoff = $now->subDays($this->settings->days(SettingKey::RetentionAuditNetworkDays))->format('Y-m-d H:i:s.uP');

        // is_local = true: the flag dies with this transaction (or savepoint), whatever happens.
        DB::select('SELECT set_config(?, ?, true)', [self::FLAG, 'on']);

        $purged = DB::update(<<<'SQL'
            WITH picked AS MATERIALIZED (
                SELECT id FROM audit_logs
                WHERE created_at < ? AND (ip_hash IS NOT NULL OR user_agent IS NOT NULL)
                ORDER BY created_at
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            UPDATE audit_logs SET ip_hash = NULL, user_agent = NULL
            FROM picked WHERE audit_logs.id = picked.id
        SQL, [$cutoff, $limit]);

        DB::select('SELECT set_config(?, ?, true)', [self::FLAG, 'off']);

        return $purged;
    }
}
