<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Follow\FollowResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to every follow and unfollow call (200, idempotent): the state after the call and the public count.
 *
 * @mixin FollowResult
 */
class FollowStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['following' => $this->following, 'followers_count' => $this->followersCount];
    }
}
