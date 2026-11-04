<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The vote-integrity summary of one product (see ProductIntegrity), with the product it is about.
 *
 * @mixin \stdClass
 * @property array<string, mixed> $resource
 */
class ProductIntegrityResource extends JsonResource
{
    private ?Product $product = null;

    public function forProduct(Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'product' => $this->product === null ? null : ['id' => $this->product->id, 'slug' => $this->product->slug, 'name' => $this->product->name],
            ...$this->resource,
        ];
    }
}
