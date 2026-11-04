<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reviewer and admin view of a product in any status. Relations appear when loaded: the queue loads maker and
 * topics, the detail loads everything. Never exposes emails.
 *
 * @mixin Product
 */
class StaffProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'website_url' => $this->website_url,
            'logo_url' => $this->logoUrl(),
            'status' => $this->status->value,
            'launch_date' => $this->launch_date?->toDateString(),
            // Full precision on purpose: this is the token the review decision must echo back (stale_submission check).
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString('microsecond'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'taken_down_at' => $this->taken_down_at?->toIso8601String(),
            'takedown_reason' => $this->takedown_reason,
            'maker' => $this->whenLoaded('maker', fn (User $maker) => ['id' => $maker->id, 'handle' => $maker->handle, 'name' => $maker->name]),
            'topics' => TopicResource::collection($this->whenLoaded('topics')),
            'links' => ProductLinkResource::collection($this->whenLoaded('links')),
            'screenshots' => ProductScreenshotResource::collection($this->whenLoaded('screenshots')),
            'reviews' => StaffReviewResource::collection($this->whenLoaded('reviews')),
        ];
    }
}
