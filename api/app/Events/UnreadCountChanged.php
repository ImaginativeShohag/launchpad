<?php

namespace App\Events;

use App\Actions\Notification\CountUnread;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The member's unread count moved. Goes out on the PRIVATE channel `user.{id}` (authorised by routes/channels.php) and the
 * frame is a hint: first paint and reconnects read GET /me/notifications/unread-count. The event holds only the user id;
 * the count and the newest unread id are read when the broadcast job runs, so a late or reordered frame is still current.
 * Nothing else goes in the frame (no title, name, href or address): frames also pass through proxies and logs.
 */
class UnreadCountChanged implements ShouldBroadcast
{
    use Dispatchable;

    /** Hand the event to the broadcaster only once the surrounding transaction has committed. */
    public bool $afterCommit = true;

    /** A lost update is replaced by the next one, and a retry could deliver an older count after a newer one. */
    public int $tries = 1;

    public function __construct(public readonly string $userId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.count';
    }

    /** @return array{unread_count: int, notification_id: string|null} */
    public function broadcastWith(): array
    {
        $unread = app(CountUnread::class);

        return [
            'unread_count' => $unread->handle($this->userId)['count'],
            'notification_id' => $unread->newestUnreadId($this->userId),
        ];
    }
}
