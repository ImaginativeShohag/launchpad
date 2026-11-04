<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What anyone may see of a visible product. No status, no review or take-down data, no maker email. Relations
 * appear when loaded (the single product loads all; the member's list loads topics only). The vote and comment counts and the
 * caller's own vote appear only after withVotes() (single read, the ranked feed and the member's product list).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    protected bool $showVotes = false;

    protected ?bool $hasVoted = null;

    /** @var array{rank: int|null, rank_date: string|null}|null */
    protected ?array $rankInfo = null;

    /** @var array{followers_count: int, is_following: bool|null}|null */
    protected ?array $makerFollow = null;

    /**
     * Adds `rank` and `rank_date` (both null when the product has no standing): the single product read only.
     *
     * @param  array{rank: int, rank_date: string}|null  $rank
     */
    public function withRank(?array $rank): static
    {
        $this->rankInfo = ['rank' => $rank['rank'] ?? null, 'rank_date' => $rank['rank_date'] ?? null];

        return $this;
    }

    /** The embedded maker card gains `followers_count`, and `is_following` when the viewer is known (null: a guest). */
    public function withMakerFollow(int $followers, ?bool $isFollowing): static
    {
        $this->makerFollow = ['followers_count' => $followers, 'is_following' => $isFollowing];

        return $this;
    }

    /**
     * Adds votes_count and comments_count, and has_voted when the caller is known (null: a guest, so the key is omitted).
     */
    public function withVotes(?bool $hasVoted): static
    {
        $this->showVotes = true;
        $this->hasVoted = $hasVoted;

        return $this;
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
            'launch_date' => $this->launch_date?->toDateString(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            ...$this->voteFields(),
            ...($this->rankInfo ?? []),
            'maker' => $this->whenLoaded('maker', fn () => $this->makerCard()),
            'topics' => TopicResource::collection($this->whenLoaded('topics')),
            'links' => ProductLinkResource::collection($this->whenLoaded('links')),
            'screenshots' => ProductScreenshotResource::collection($this->whenLoaded('screenshots')),
        ];
    }

    private function makerCard(): PublicUserResource
    {
        $card = new PublicUserResource($this->maker);

        return $this->makerFollow === null
            ? $card
            : $card->withFollow(['followers_count' => $this->makerFollow['followers_count']], $this->makerFollow['is_following']);
    }

    /** @return array<string, mixed> */
    protected function voteFields(): array
    {
        if (! $this->showVotes) {
            return [];
        }

        return ['votes_count' => $this->votes_count, 'comments_count' => $this->comments_count] + ($this->hasVoted === null ? [] : ['has_voted' => $this->hasVoted]);
    }
}
