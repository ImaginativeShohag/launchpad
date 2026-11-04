<?php

namespace App\Actions\Notification;

use App\Actions\Notification\Concerns\AnnouncesUnreadCount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarkNotificationsRead
{
    use AnnouncesUnreadCount;

    /**
     * Marks the member's own unread rows read with ONE statement and returns how many really changed (a foreign, unknown
     * or already-read id is silently not counted, so the answer is no existence oracle). Idempotent: a repeat returns 0.
     *
     * `$all` marks what existed before the call started: the cutoff is read BEFORE the statement, so a notification
     * that arrives during the call has a later `created_at` and stays unread.
     *
     * @param  list<string>|null  $ids  UUID strings, already shape-validated
     *
     * @throws InvalidArgumentException unless exactly one of `$ids` and `$all` is given (a programming error)
     */
    public function handle(string $userId, ?array $ids, bool $all): int
    {
        if (($ids !== null) === $all) {
            throw new InvalidArgumentException('Pass exactly one of $ids and $all.');
        }

        $now = Carbon::now('UTC')->format('Y-m-d H:i:s.uP');
        $marked = 0;

        // The rows are locked in id order first (a CTE the planner must not fold into the UPDATE), so two tabs marking
        // overlapping sets never lock them in opposite orders. GREATEST: the CHECK needs read_at >= created_at, and a row
        // stamped by a faster clock must not turn a read into a 500.
        $update = 'UPDATE notifications SET read_at = GREATEST(?::timestamptz, notifications.created_at), updated_at = GREATEST(?::timestamptz, notifications.created_at) FROM picked WHERE notifications.id = picked.id';
        $pick = 'WITH picked AS MATERIALIZED (SELECT id FROM notifications WHERE user_id = ? AND read_at IS NULL%s ORDER BY id FOR UPDATE) ';

        if ($ids !== null) {
            if ($ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?::uuid'));
                $marked = DB::affectingStatement(sprintf($pick, " AND id IN ({$placeholders})").$update, [$userId, ...$ids, $now, $now]);
            }
        } else {
            $marked = DB::affectingStatement(sprintf($pick, ' AND created_at <= ?').$update, [$userId, $now, $now, $now]);
        }

        if ($marked > 0) {
            $this->announceUnreadCount($userId);
        }

        return $marked;
    }
}
