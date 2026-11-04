<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductReview */
class ProductReviewResource extends JsonResource
{
    /** @param  bool  $current  whether this is the decision the product's current submission is waiting on */
    public function __construct($resource, private readonly bool $current = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision->value,
            'note' => $this->note,
            'current' => $this->current,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
