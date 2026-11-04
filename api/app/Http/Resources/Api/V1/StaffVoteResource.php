<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vote as staff see it: the voter, its status, and 8-character device / IP fingerprints (`device_fp` / `ip_fp`, computed
 * in SQL by ProductVoteQuery, null once the hashes were purged). Expects `user` loaded.
 *
 * @mixin Vote
 */
class StaffVoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'voted_at' => $this->voted_at->toIso8601String(),
            'removed' => $this->removed_at !== null,
            'toggle_count' => $this->toggle_count,
            'voter' => ['id' => $this->user?->id, 'handle' => $this->user?->handle, 'risk_score' => $this->user?->risk_score],
            'device_fp' => $this->getAttribute('device_fp'),
            'ip_fp' => $this->getAttribute('ip_fp'),
        ];
    }
}
