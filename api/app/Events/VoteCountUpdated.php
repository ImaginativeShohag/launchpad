<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product's vote count changed. Goes out on the PUBLIC channel `product.{id}` (anyone who knows the ULID may listen),
 * so the payload is exactly the product id and the count: never a voter, a user id or anything else.
 */
class VoteCountUpdated implements ShouldBroadcast
{
    use Dispatchable;

    /** Hand the event to the broadcaster only once the surrounding transaction has committed. */
    public bool $afterCommit = true;

    /** A lost update is replaced by the next one, and a retry could deliver an older count after a newer one. */
    public int $tries = 1;

    public function __construct(public readonly string $productId, public readonly int $votesCount) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('product.'.$this->productId)];
    }

    public function broadcastAs(): string
    {
        return 'vote.count';
    }

    /** @return array{product_id: string, votes_count: int} */
    public function broadcastWith(): array
    {
        return ['product_id' => $this->productId, 'votes_count' => $this->votesCount];
    }
}
