<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductScreenshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductScreenshot */
class ProductScreenshotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'position' => $this->position,
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
        ];
    }
}
