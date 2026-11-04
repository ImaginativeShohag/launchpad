<?php

namespace App\Actions\Notification\Concerns;

use App\Events\UnreadCountChanged;
use Throwable;

trait AnnouncesUnreadCount
{
    /**
     * Tells the member's other tabs that the count moved. The broadcast is a hint: a broker or Reverb outage must never
     * fail the committed write that caused it, so a failure is reported and swallowed.
     */
    private function announceUnreadCount(string $userId): void
    {
        try {
            UnreadCountChanged::dispatch($userId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
