<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What anyone may see on /users/{handle}. No email, role or flags. A suspended member shows
 * only their name and handle. Expects `links` to be loaded.
 *
 * @mixin User
 */
class PublicUserResource extends JsonResource
{
    /** @var array{followers_count: int, following_count?: int}|null */
    protected ?array $followCounts = null;

    protected ?bool $isFollowing = null;

    protected ?int $productsCount = null;

    /** Adds `products_count` (the member's publicly listed products). Only the profile read calls it; maker cards carry none. */
    public function withProductsCount(int $count): static
    {
        $this->productsCount = $count;

        return $this;
    }

    /**
     * Adds the public follow counts, and `is_following` when the viewer is known (null: a guest, so the key is omitted).
     * The maker cards on product reads do not call this, so they carry no counts.
     *
     * @param  array{followers_count: int, following_count?: int}  $counts
     */
    public function withFollow(array $counts, ?bool $isFollowing): static
    {
        $this->followCounts = $counts;
        $this->isFollowing = $isFollowing;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $suspended = $this->isSuspended();

        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'headline' => $suspended ? null : $this->headline,
            'avatar_url' => $suspended ? null : $this->avatarUrl(),
            'links' => $suspended ? [] : UserLinkResource::collection($this->whenLoaded('links')),
            'is_suspended' => $suspended,
            'joined_at' => $this->created_at?->toIso8601String(),
            ...($this->productsCount === null ? [] : ['products_count' => $this->productsCount]),
            ...($this->followCounts ?? []),
            ...($this->isFollowing === null ? [] : ['is_following' => $this->isFollowing]),
        ];
    }
}
