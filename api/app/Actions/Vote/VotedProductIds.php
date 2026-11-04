<?php

namespace App\Actions\Vote;

use App\Models\User;
use App\Models\Vote;

/** Which of the given products the user has an active vote on: one query, however many ids. */
class VotedProductIds
{
    /**
     * @param  list<string>  $productIds
     * @return array<string, bool> product id => true
     */
    public function handle(User $user, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Vote::active()
            ->where('user_id', $user->getKey())
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();
    }
}
