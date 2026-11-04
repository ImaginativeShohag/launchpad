<?php

namespace App\Http\Resources\Api\V1;

/**
 * A card of a ranked list (today, a day): the public product fields plus votes_count, the 1-based rank, comments_count
 * and, for a known caller, has_voted.
 */
class RankedProductResource extends ProductResource
{
    private int $rank = 0;

    public function ranked(int $rank, ?bool $hasVoted): static
    {
        $this->rank = $rank;

        return $this->withVotes($hasVoted);
    }

    /** @return array<string, mixed> */
    protected function voteFields(): array
    {
        $fields = parent::voteFields();

        return ['votes_count' => $fields['votes_count'], 'rank' => $this->rank] + array_diff_key($fields, ['votes_count' => true]);
    }
}
