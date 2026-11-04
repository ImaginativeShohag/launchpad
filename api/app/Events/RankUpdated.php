<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Today's order changed. Goes out on the PUBLIC channel `today`, so the payload is the launch day and, for the top
 * products, only the id, the position and the vote count: no hot score, no name, nothing about any member.
 *
 * Published NOW (not queued a second time): BroadcastRankUpdate reads the order and publishes it in one job, so a
 * frame can never carry an order that was read earlier than a newer frame, and its try/catch covers a down Reverb
 * whatever the queue driver is.
 *
 * Reverb rejects a publish whose HTTP request exceeds `max_request_size` (`REVERB_MAX_REQUEST_SIZE`, 10,000 bytes by
 * default; the inbound websocket limit `max_message_size` is a different thing). The top 100 are about 8.8 KB of body
 * (the Pusher envelope escapes the JSON), about 9.3 KB with headers: a test pins that, and a too-large publish fails
 * loudly (a 4xx, so a BroadcastException that the listener reports).
 */
class RankUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  string  $date  launch day, Y-m-d (UTC)
     * @param  list<array{product_id: string, rank: int, votes_count: int}>  $items
     */
    public function __construct(public readonly string $date, public readonly array $items) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('today')];
    }

    public function broadcastAs(): string
    {
        return 'rank.updated';
    }

    /** @return array{date: string, items: list<array{product_id: string, rank: int, votes_count: int}>} */
    public function broadcastWith(): array
    {
        return ['date' => $this->date, 'items' => $this->items];
    }
}
