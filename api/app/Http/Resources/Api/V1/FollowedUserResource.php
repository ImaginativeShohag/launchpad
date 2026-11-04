<?php

namespace App\Http\Resources\Api\V1;

use App\Models\UserFollow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /me/follows/users: the member I follow. A suspended member shows only name and handle, like
 * PublicUserResource. Expects `followed` to be loaded.
 *
 * @mixin UserFollow
 */
class FollowedUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $member = $this->followed;
        $suspended = $member->isSuspended();

        return [
            'id' => $member->id,
            'handle' => $member->handle,
            'name' => $member->name,
            'headline' => $suspended ? null : $member->headline,
            'avatar_url' => $suspended ? null : $member->avatarUrl(),
            'is_suspended' => $suspended,
            'followed_at' => $this->created_at->toIso8601String(),
        ];
    }
}
