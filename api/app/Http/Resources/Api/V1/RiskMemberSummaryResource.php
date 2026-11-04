<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The member risk summary built by RiskMemberSummary. Profile basics only: never the e-mail, avatar path or any hash.
 *
 * @property array<string, mixed> $resource
 */
class RiskMemberSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource['user'];
        $signals = $this->resource['signals']->map(fn (RiskSignal $s) => RiskSignalResource::make($s)->resolve($request))->all();

        return [
            'id' => $user->id,
            'handle' => $user->handle,
            'name' => $user->name,
            'role' => $user->role->value,
            'created_at' => $user->created_at?->toIso8601String(),
            'suspended' => $user->isSuspended(),
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'suspended_reason' => $user->suspended_reason,
            'deleted' => $user->isDeleted(),
            'risk_score' => $user->risk_score,
            'breakdown' => $this->resource['breakdown'],
            'votes' => $this->resource['votes'],
            'sign_ins' => $this->resource['sign_ins'],
            'linked_accounts' => $this->resource['linked_accounts'],
            'signals' => $signals,
        ];
    }
}
