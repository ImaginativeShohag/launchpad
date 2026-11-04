<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the staff product list: no description, links or screenshots (the detail read has them). Maker and topics
 * come from the list's eager loads. Never exposes e-mails.
 *
 * @mixin Product
 */
class StaffProductRowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'logo_url' => $this->logoUrl(),
            'status' => $this->status->value,
            'launch_date' => $this->launch_date?->toDateString(),
            'votes_count' => $this->votes_count,
            'comments_count' => $this->comments_count,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'taken_down_at' => $this->taken_down_at?->toIso8601String(),
            'takedown_reason' => $this->takedown_reason,
            'maker' => $this->whenLoaded('maker', fn (User $maker) => ['id' => $maker->id, 'handle' => $maker->handle, 'name' => $maker->name]),
            'topics' => TopicResource::collection($this->whenLoaded('topics')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
