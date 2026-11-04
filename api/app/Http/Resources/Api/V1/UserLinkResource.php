<?php

namespace App\Http\Resources\Api\V1;

use App\Models\UserLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserLink */
class UserLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'url' => $this->url,
            'position' => $this->position,
        ];
    }
}
