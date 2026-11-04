<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ProductStatus;
use App\Enums\ReviewDecision;
use App\Models\Product;
use App\Models\ProductReview;
use App\Policies\ProductPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The maker's own view, in every status. `editable_fields` and `media_editable` tell the client what the
 * current status allows, so the UI never has to duplicate the edit-rule table. Relations appear when loaded.
 *
 * @mixin Product
 */
class FounderProductResource extends JsonResource
{
    /**
     * The whole history, oldest first, with `current` on the one decision the present state came from: the latest
     * review whose decision matches the status. A product only reaches changes_requested or rejected through a
     * decision, and leaves it by resubmitting (pending) or withdrawing, so the latest matching review is always the
     * current one. No timestamp comparison: review times are whole seconds and `submitted_at` has microseconds.
     *
     * @return array<int, ProductReviewResource>
     */
    private function reviewsWithCurrentFlag(): array
    {
        $decision = match ($this->status) {
            ProductStatus::ChangesRequested => ReviewDecision::ChangesRequested,
            ProductStatus::Rejected => ReviewDecision::Rejected,
            default => null,
        };

        $current = $decision === null ? null : $this->reviews->filter(fn (ProductReview $review) => $review->decision === $decision)->last();

        return $this->reviews->map(fn (ProductReview $review) => new ProductReviewResource($review, $current?->id === $review->id))->values()->all();
    }

    /**
     * Only live and taken-down products have public counters worth showing; the key is omitted in other statuses.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return in_array($this->status, [ProductStatus::Live, ProductStatus::TakenDown], true)
            ? ['votes_count' => $this->votes_count, 'comments_count' => $this->comments_count]
            : [];
    }

    /**
     * The decision behind changes_requested / rejected, when the list attached it (AttachLatestReviews). Omitted otherwise.
     *
     * @return array<string, mixed>
     */
    private function latestReview(): array
    {
        if (! $this->relationLoaded('latestReview')) {
            return [];
        }

        /** @var ProductReview|null $review */
        $review = $this->getRelation('latestReview');

        return ['latest_review' => $review === null ? null : [
            'decision' => $review->decision->value,
            'note' => $review->note,
            'decided_at' => $review->created_at->toIso8601String(),
        ]];
    }

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
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'taken_down_at' => $this->taken_down_at?->toIso8601String(),
            'takedown_reason' => $this->takedown_reason,
            'editable_fields' => ProductPolicy::editableFields($this->status),
            'media_editable' => ProductPolicy::mediaEditable($this->status),
            'links' => ProductLinkResource::collection($this->whenLoaded('links')),
            'topics' => TopicResource::collection($this->whenLoaded('topics')),
            'screenshots' => ProductScreenshotResource::collection($this->whenLoaded('screenshots')),
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviewsWithCurrentFlag()),
            ...$this->counts(),
            ...$this->latestReview(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
