<?php

namespace App\Actions\Product\Concerns;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait LocksOwnedProduct
{
    /**
     * Re-reads the product under a row lock (the model handed in may be stale) and re-checks the owner.
     * A deleted or foreign product is "not found", as everywhere else.
     *
     * @throws ModelNotFoundException
     */
    protected function lockOwned(User $actor, Product $product): Product
    {
        $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->maker_id !== $actor->getKey()) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        return $locked;
    }
}
