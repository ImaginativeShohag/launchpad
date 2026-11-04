<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

/**
 * A card of the following feed: the public card plus votes_count, comments_count, the caller's has_voted and the
 * reasons the product is in the feed. The caller is always a known member.
 */
class FeedProductResource extends ProductResource
{
    /** @var list<array<string, mixed>> */
    private array $reasons = [];

    /** @param  list<array<string, mixed>>  $reasons */
    public function feedFor(bool $hasVoted, array $reasons): static
    {
        $this->reasons = $reasons;

        return $this->withVotes($hasVoted);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'reasons' => $this->reasons];
    }
}
