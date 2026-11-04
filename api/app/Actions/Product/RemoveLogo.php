<?php

namespace App\Actions\Product;

use App\Actions\Media\StoreImage;
use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Support\Facades\DB;

class RemoveLogo
{
    use LocksOwnedProduct;

    public function __construct(private readonly StoreImage $images) {}

    public function handle(User $actor, Product $product): void
    {
        $old = DB::transaction(function () use ($actor, $product): ?string {
            $locked = $this->lockOwned($actor, $product);

            if (! ProductPolicy::mediaEditable($locked->status)) {
                throw ProductRuleViolation::locked();
            }

            $old = $locked->logo_path;
            $locked->forceFill(['logo_path' => null])->save();

            return $old;
        });

        $this->images->discard((string) config('launchpad.product_disk'), $old);
    }
}
