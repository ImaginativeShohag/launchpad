<?php

namespace App\Actions\Notification;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListNotifications
{
    /**
     * One keyset page of the member's own notifications, newest first (ties fall back to the id). Served by
     * notifications_user_index, or notifications_unread_index when only unread rows are asked for.
     *
     * @return CursorPaginator<int, Notification>
     */
    public function handle(string $userId, bool $unreadOnly, int $perPage): CursorPaginator
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
