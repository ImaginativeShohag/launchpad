<?php

namespace App\Actions\Retention;

use App\Actions\Notification\Reconcile;
use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes finished outbox rows (`sent`, `failed`, `suppressed`) older than `retention.email_messages_days`; the rows hold
 * the recipient's address, so they must expire. A `queued` or `sending` row is never purged, however old. The WHERE text
 * repeats the predicate of email_messages_terminal_created_index, so the index serves it.
 *
 * A purged `sent` row frees its idempotency key. That is safe only because every producer re-derives rows inside a window
 * far shorter than the retention (the 48 hour reconcile sweep, same-day digests), so the age used here never drops below
 * `Reconcile::MIN_EMAIL_RETENTION_HOURS`, whatever the setting says. No user or product row is locked.
 */
class PurgeEmailMessages extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'email_messages';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        $hours = max($this->settings->days(SettingKey::RetentionEmailMessagesDays) * 24, Reconcile::MIN_EMAIL_RETENTION_HOURS);
        $cutoff = $now->subHours($hours)->format('Y-m-d H:i:s.uP');

        return DB::delete(
            "WITH picked AS MATERIALIZED (SELECT id FROM email_messages WHERE status IN ('sent', 'failed', 'suppressed') AND created_at < ? ORDER BY created_at LIMIT ? FOR UPDATE SKIP LOCKED) DELETE FROM email_messages USING picked WHERE email_messages.id = picked.id",
            [$cutoff, $limit],
        );
    }
}
