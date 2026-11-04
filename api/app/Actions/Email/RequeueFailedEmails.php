<?php

namespace App\Actions\Email;

use App\Enums\EmailType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manual recovery: puts `failed` rows back in the queue (attempts 0, due now) so the next `launchpad:send-emails` run
 * tries them again, for example after a provider misconfiguration failed a whole batch. Idempotent (a requeued row is no
 * longer `failed`), chunked, each chunk picked in a materialised CTE with SKIP LOCKED. Whether the recipient is still
 * deliverable is decided at send time, as for every row.
 */
class RequeueFailedEmails
{
    public const CHUNK = 500;

    private const MAX_CHUNKS = 1000;

    /** @return int rows put back in the queue */
    public function handle(CarbonImmutable $now, int $sinceHours, ?EmailType $type = null): int
    {
        $moment = $now->utc()->format('Y-m-d H:i:s.uP');
        $since = $now->subHours($sinceHours)->utc()->format('Y-m-d H:i:s.uP');
        $total = 0;

        for ($chunk = 0; $chunk < self::MAX_CHUNKS; $chunk++) {
            $bindings = [$since];
            $typeSql = '';
            if ($type !== null) {
                $typeSql = ' AND type = ?';
                $bindings[] = $type->value;
            }

            $done = DB::affectingStatement(
                "WITH picked AS MATERIALIZED (
                    SELECT id FROM email_messages WHERE status = 'failed' AND updated_at >= ?{$typeSql}
                    ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED
                )
                UPDATE email_messages m SET status = 'queued', attempts = 0, next_attempt_at = ?, last_error = NULL, updated_at = ?
                FROM picked p WHERE m.id = p.id",
                [...$bindings, self::CHUNK, $moment, $moment],
            );

            $total += $done;

            if ($done < self::CHUNK) {
                break;
            }
        }

        return $total;
    }
}
