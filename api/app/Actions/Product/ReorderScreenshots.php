<?php

namespace App\Actions\Product;

use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\ScreenshotOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderScreenshots
{
    use LocksOwnedProduct;

    /** @param  list<string>  $ids  every screenshot id of the product, exactly once, in the wanted order */
    public function handle(User $actor, Product $product, array $ids): Product
    {
        DB::transaction(function () use ($actor, $product, $ids): void {
            $locked = $this->lockOwned($actor, $product);

            if (! ProductPolicy::mediaEditable($locked->status)) {
                throw ProductRuleViolation::locked();
            }

            $existing = $locked->screenshots()->pluck('id')->all();
            $sortedGiven = $ids;
            sort($sortedGiven);
            sort($existing);

            if ($sortedGiven !== $existing) {
                throw ValidationException::withMessages(['ids' => ['Send every screenshot of the product exactly once.']]);
            }

            ScreenshotOrder::apply($locked, $ids);
        });

        return $product;
    }
}
