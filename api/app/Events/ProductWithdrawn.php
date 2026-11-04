<?php

namespace App\Events;

/** The status the product left is included because by the time a listener runs the row is already a draft. */
final class ProductWithdrawn extends ProductEvent
{
    public function __construct(string $productId, ?string $actorId = null, public readonly ?string $fromStatus = null)
    {
        parent::__construct($productId, $actorId);
    }
}
