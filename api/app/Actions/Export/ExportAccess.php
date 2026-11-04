<?php

namespace App\Actions\Export;

use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Who may touch an export: only the maker of a non-deleted product, and only exports of that product that are theirs.
 * Ids are plain strings (route-model binding would run before the guards). Every other case, a foreign, deleted, unknown
 * or malformed id, is the one uniform 404. Plain reads; takes no lock.
 */
class ExportAccess
{
    /** @throws ModelNotFoundException */
    public function product(User $actor, string $productId): Product
    {
        if (! Str::isUlid($productId)) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        // Soft-deleted products are excluded by the model.
        $product = Product::query()->find($productId);

        if ($product === null || $product->maker_id !== $actor->getKey()) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        return $product;
    }

    /** @throws ModelNotFoundException */
    public function export(User $actor, string $productId, string $exportId): Export
    {
        $product = $this->product($actor, $productId);

        if (! Str::isUlid($exportId)) {
            throw (new ModelNotFoundException)->setModel(Export::class, [$exportId]);
        }

        // The id is stored lower-case; ULIDs are case-insensitive, so compare the canonical form.
        return Export::query()->whereKey(strtolower($exportId))
            ->where('product_id', $product->getKey())->where('user_id', $actor->getKey())->firstOrFail();
    }
}
