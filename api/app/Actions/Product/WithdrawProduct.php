<?php

namespace App\Actions\Product;

use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Enums\ProductStatus;
use App\Events\ProductWithdrawn;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class WithdrawProduct
{
    use LocksOwnedProduct;

    /**
     * pending, approved or scheduled -> draft, clearing the launch day. Racing the go-live scheduler is safe:
     * both lock the row, so exactly one wins and the loser sees the new status.
     *
     * @throws ProductRuleViolation from any other status
     */
    public function handle(User $actor, Product $product): Product
    {
        DB::transaction(function () use ($actor, $product): void {
            $locked = $this->lockOwned($actor, $product);
            $from = $locked->status;
            $locked->transitionTo(ProductStatus::Draft);
            $locked->forceFill(['launch_date' => null, 'approved_at' => null, 'submitted_at' => null])->save();

            // Withdrawing an approved or scheduled product discards an approval: keep a trace of who and from where.
            Audit::record('product.withdrawn', $actor, $locked, ['from_status' => $from->value]);
            ProductWithdrawn::dispatch((string) $locked->getKey(), (string) $actor->getKey(), $from->value);
            $product->setRawAttributes($locked->getAttributes(), true);
        });

        return $product;
    }
}
