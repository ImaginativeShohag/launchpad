<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the members-by-risk list (the "Suspicious voting" panel). Expects `open_signals` and `flagged_votes` set by
 * RiskMemberQuery. No e-mail.
 *
 * @mixin User
 */
class RiskMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'risk_score' => $this->risk_score,
            'suspended' => $this->isSuspended(),
            'account_age_days' => (int) floor(max(0, now()->getTimestamp() - ($this->created_at?->getTimestamp() ?? 0)) / 86400),
            'open_signals' => $this->getAttribute('open_signals'),
            'flagged_votes' => $this->getAttribute('flagged_votes'),
        ];
    }
}
