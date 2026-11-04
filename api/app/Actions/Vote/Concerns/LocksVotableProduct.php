<?php

namespace App\Actions\Vote\Concerns;

use App\Actions\Comment\Concerns\LocksCommentableProduct;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait LocksVotableProduct
{
    use LocksCommentableProduct;

    /**
     * Steps 1 to 4 of the voting rules, inside the caller's transaction: the product row is locked first (global
     * lock order, step 1) and everything is checked on the locked row. The voter is re-read under FOR SHARE (after
     * the product row), so a concurrent account deletion is waited for and seen; the vote insert's FOR KEY SHARE is compatible.
     * The order of the checks is pinned by tests: visibility, the launch day, the own product, then the voter.
     *
     * @throws ModelNotFoundException the uniform 404 for an unknown, hidden, soft-deleted or deleted-maker product
     * @throws ProductRuleViolation 409 `voting_closed` outside the launch day, 403 `own_product`
     * @throws AuthorizationException when the voter was suspended or deleted since the request was authorised
     */
    private function lockVotable(User $voter, string $productId): Product
    {
        // One clock read for the visibility check and the closed-day check.
        $product = $this->lockPublicProduct($productId, $today);

        if ($product->launch_date?->toDateString() !== $today) {
            throw ProductRuleViolation::votingClosed();
        }

        if ($product->maker_id === $voter->getKey()) {
            throw ProductRuleViolation::ownProduct('vote for');
        }

        // Order is product row, then this row (never the reverse).
        $this->assertActorAvailable($voter);

        return $product;
    }
}
