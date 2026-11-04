<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in member's own view of their account.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'pending_email' => $this->pending_email,
            'has_password' => $this->hasPassword(),
            'role' => $this->role->value,
            'headline' => $this->headline,
            'avatar_url' => $this->avatarUrl(),
            'links' => UserLinkResource::collection($this->whenLoaded('links')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
