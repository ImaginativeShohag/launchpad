<?php

namespace App\Actions\Product;

use App\Actions\Media\StoreImage;
use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class SetLogo
{
    use LocksOwnedProduct;

    public function __construct(private readonly StoreImage $images) {}

    /** @throws ProductRuleViolation when the product's status no longer allows media */
    public function handle(User $actor, Product $product, UploadedFile $file): Product
    {
        // Cheap early refusal so a locked product costs no image decoding; re-checked on the locked row below.
        if (! ProductPolicy::mediaEditable($product->status)) {
            throw ProductRuleViolation::locked();
        }

        $disk = (string) config('launchpad.product_disk');
        $stored = $this->images->store($file, $disk, 'products/logos', 'logo', square: 512);

        try {
            $old = DB::transaction(function () use ($actor, $product, $stored): ?string {
                $locked = $this->lockOwned($actor, $product);

                if (! ProductPolicy::mediaEditable($locked->status)) {
                    throw ProductRuleViolation::locked();
                }

                $old = $locked->logo_path;
                $locked->forceFill(['logo_path' => $stored->path])->save();
                $product->setRawAttributes($locked->getAttributes(), true);

                return $old;
            });
        } catch (Throwable $e) {
            $this->images->discard($disk, $stored->path);

            throw $e;
        }

        $this->images->discard($disk, $old);

        return $product;
    }
}
