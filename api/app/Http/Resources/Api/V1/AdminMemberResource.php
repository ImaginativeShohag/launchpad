<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * One row of the admin member directory: the admin user card (which already carries the e-mail for admins) plus account
 * state and the risk score. Never a hash, token or avatar path.
 *
 * @mixin User
 */
class AdminMemberResource extends AdminUserResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_suspended' => $this->isSuspended(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspended_reason' => $this->suspended_reason,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'risk_score' => $this->risk_score,
        ];
    }
}
