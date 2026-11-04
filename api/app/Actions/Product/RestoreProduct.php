<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Events\ProductRestored;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RestoreProduct
{
    /**
     * taken_down -> live, clearing the take-down fields (the audit log keeps the history).
     *
     * @throws AuthorizationException when the actor is no longer an active admin
     * @throws ProductRuleViolation 409 unless the product is taken down, or when its maker is suspended or deleted
     */
    public function handle(User $admin, Product $product): Product
    {
        DB::transaction(function () use ($admin, $product): void {
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

            // Explicit: scheduled -> live is a legal edge (it is the scheduler's), but it is not a restore.
            if ($locked->status !== ProductStatus::TakenDown) {
                throw ProductRuleViolation::invalidStatus();
            }

            // A suspended or deleted maker's product must not come back to the public through a restore.
            $makerActive = DB::table('users')->where('id', $locked->maker_id)->whereNull('deleted_at')->whereNull('suspended_at')->exists();

            if (! $makerActive) {
                throw ProductRuleViolation::makerUnavailable();
            }

            $locked->transitionTo(ProductStatus::Live);
            $locked->forceFill(['taken_down_at' => null, 'taken_down_by' => null, 'takedown_reason' => null])->save();

            Audit::record('product.restored', $admin, $locked, ['product_id' => $locked->getKey()]);
            ProductRestored::dispatch((string) $locked->getKey(), (string) $admin->getKey());

            $product->setRawAttributes($locked->getAttributes(), true);
        });

        return $product;
    }
}
