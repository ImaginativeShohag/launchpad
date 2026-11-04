<?php

namespace App\Actions\Notification;

use Illuminate\Support\Facades\DB;

class CountUnread
{
    /** The badge shows "100+" at most, so the count stops there (served by notifications_unread_index). */
    public const CAP = 100;

    /** @return array{count: int, capped: bool} */
    public function handle(string $userId): array
    {
        $row = (array) DB::selectOne(
            'SELECT count(*) AS n FROM (SELECT 1 FROM notifications WHERE user_id = ? AND read_at IS NULL LIMIT '.self::CAP.') t',
            [$userId],
        );
        $count = is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;

        return ['count' => $count, 'capped' => $count >= self::CAP];
    }

    /** The newest unread notification, so a client can fetch the list and shake the bell. */
    public function newestUnreadId(string $userId): ?string
    {
        $row = (array) DB::selectOne(
            'SELECT id FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1',
            [$userId],
        );

        return is_string($row['id'] ?? null) ? $row['id'] : null;
    }
}
