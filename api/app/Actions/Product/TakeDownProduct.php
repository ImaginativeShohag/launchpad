<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Events\ProductTakenDown;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class TakeDownProduct
{
    /**
     * live -> taken_down, with a reason shown to the founder. The admin role is re-read inside the transaction
     * (the model handed in may be stale). The audit `meta` keeps the short staff-written reason, so earlier reasons
     * survive a restore and a second take-down.
     *
     * @throws AuthorizationException when the actor is no longer an active admin
     * @throws ProductRuleViolation 409 unless the product is live
     */
    public function handle(User $admin, Product $product, string $reason): Product
    {
        DB::transaction(function () use ($admin, $product, $reason): void {
            // Staff check first (a plain read, no lock), then the product row, as DecideReview does.
            $role = DB::table('users')->where('id', $admin->getKey())->whereNull('deleted_at')->whereNull('suspended_at')->value('role');

            if ($role !== Role::Admin->value) {
                throw new AuthorizationException;
            }

            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            // Drafts are the founder's private work: the same 404 as an unknown id.
            if ($locked->status === ProductStatus::Draft) {
                throw (new ModelNotFoundException)->setModel(Product::class);
            }

            $locked->transitionTo(ProductStatus::TakenDown);
            $locked->forceFill([
                'taken_down_at' => now(),
                'taken_down_by' => $admin->getKey(),
                'takedown_reason' => $reason,
            ])->save();

            Audit::record('product.taken_down', $admin, $locked, ['product_id' => $locked->getKey(), 'reason' => $reason]);
            ProductTakenDown::dispatch((string) $locked->getKey(), (string) $admin->getKey());

            $product->setRawAttributes($locked->getAttributes(), true);
        });

        return $product;
    }
}
