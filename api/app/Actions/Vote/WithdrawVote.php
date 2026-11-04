<?php

namespace App\Actions\Vote;

use App\Actions\Vote\Concerns\LocksVotableProduct;
use App\Events\VoteRemoved;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;

class WithdrawVote
{
    use LocksVotableProduct;

    /**
     * Takes the member's active vote back. Idempotent: no active vote (none, already withdrawn, voided) is a no-op
     * that fires nothing and does not reveal a void. The bucket of the ORIGINAL `voted_at` hour is decremented, and
     * `votes_count` never goes below zero.
     *
     * @return array{voted: bool, votes_count: int}
     */
    public function handle(User $voter, string $productId): array
    {
        return DB::transaction(function () use ($voter, $productId): array {
            $product = $this->lockVotable($voter, $productId);
            $count = $product->votes_count;

            $vote = Vote::query()->where('user_id', $voter->getKey())->where('product_id', $productId)->active()->lockForUpdate()->first();

            if ($vote === null) {
                return ['voted' => false, 'votes_count' => $count];
            }

            $vote->forceFill(['removed_at' => now()->startOfSecond()])->save();
            $count = (int) DB::selectOne('UPDATE products SET votes_count = greatest(votes_count - 1, 0) WHERE id = ? RETURNING votes_count', [$productId])->votes_count;
            HourlyBuckets::add($productId, $vote->voted_at, -1);
            VoteRemoved::dispatch($productId, (string) $voter->getKey());

            return ['voted' => false, 'votes_count' => $count];
        });
    }
}
