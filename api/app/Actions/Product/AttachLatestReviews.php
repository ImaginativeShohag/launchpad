<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Enums\ReviewDecision;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Support\Collection;

/**
 * Sets the `latestReview` relation of the changes_requested and rejected products of a page: the latest review whose
 * decision matches the status (the same rule as FounderProductResource's `current`). One query for the whole page.
 */
class AttachLatestReviews
{
    /** @param  iterable<Product>  $products */
    public function handle(iterable $products): void
    {
        $byStatus = Collection::make($products)->filter(
            fn (Product $product): bool => in_array($product->status, [ProductStatus::ChangesRequested, ProductStatus::Rejected], true)
        );

        $latest = $byStatus->isEmpty() ? collect() : ProductReview::query()
            ->whereIn('product_id', $byStatus->map(fn (Product $product): mixed => $product->getKey())->all())
            ->whereIn('decision', [ReviewDecision::ChangesRequested->value, ReviewDecision::Rejected->value])
            ->orderBy('product_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('product_id');

        foreach ($byStatus as $product) {
            $decision = $product->status === ProductStatus::Rejected ? ReviewDecision::Rejected : ReviewDecision::ChangesRequested;
            $match = $latest->get($product->getKey(), collect())->first(fn (ProductReview $review): bool => $review->decision === $decision);
            $product->setRelation('latestReview', $match);
        }
    }
}
