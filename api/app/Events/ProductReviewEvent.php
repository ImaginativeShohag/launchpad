<?php

namespace App\Events;

/** A review decision; carries the id of the `product_reviews` row as well. */
abstract class ProductReviewEvent extends ProductEvent
{
    public function __construct(string $productId, ?string $actorId, public readonly string $reviewId)
    {
        parent::__construct($productId, $actorId);
    }
}
