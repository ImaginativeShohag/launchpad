<?php

namespace App\Actions\Product;

use App\Actions\Media\StoreImage;
use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\ProductScreenshot;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\ScreenshotOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemoveScreenshot
{
    use LocksOwnedProduct;

    public function __construct(private readonly StoreImage $images) {}

    /** @throws ModelNotFoundException when the screenshot is not this product's */
    public function handle(User $actor, Product $product, string $screenshotId): void
    {
        // The id comes from the URL: a non-ULID (or invalid UTF-8) value must be a 404, never reach the database.
        if (! Str::isUlid($screenshotId)) {
            throw (new ModelNotFoundException)->setModel(ProductScreenshot::class);
        }

        $path = DB::transaction(function () use ($actor, $product, $screenshotId): string {
            $locked = $this->lockOwned($actor, $product);

            if (! ProductPolicy::mediaEditable($locked->status)) {
                throw ProductRuleViolation::locked();
            }

            $screenshot = $locked->screenshots()->whereKey($screenshotId)->firstOrFail();
            $screenshot->delete();

            // Close the gap so positions stay 0..n-1.
            ScreenshotOrder::apply($locked, array_values($locked->screenshots()->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()));

            return $screenshot->path;
        });

        $this->images->discard((string) config('launchpad.product_disk'), $path);
    }
}
