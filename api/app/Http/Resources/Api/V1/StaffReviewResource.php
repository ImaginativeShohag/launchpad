<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as staff see it: who decided (id and public handle only) next to the decision.
 *
 * @mixin ProductReview
 */
class StaffReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision->value,
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
            'reviewer' => $this->whenLoaded('reviewer', fn (User $reviewer) => ['id' => $reviewer->id, 'handle' => $reviewer->handle]),
        ];
    }
}
