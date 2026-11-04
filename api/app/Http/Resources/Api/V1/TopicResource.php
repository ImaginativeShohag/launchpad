<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Topic */
class TopicResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'emoji' => $this->emoji,
            'description' => $this->description,
            'position' => $this->position,
        ];
    }
}
