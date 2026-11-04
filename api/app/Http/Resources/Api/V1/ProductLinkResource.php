<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductLink */
class ProductLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind->value, 'url' => $this->url, 'position' => $this->position];
    }
}
