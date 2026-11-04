<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One risk signal as staff read it in a list. Expects `user`, `product` and `reviewer` loaded. A deleted member is the
 * anonymised row they became; there is no e-mail, no hash and no address anywhere in it.
 *
 * @mixin RiskSignal
 */
class RiskSignalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $details = $this->details;

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status(),
            'weight' => $this->weight,
            'detected_at' => $this->detected_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewer' => $this->reviewer === null ? null : ['id' => $this->reviewer->id, 'handle' => $this->reviewer->handle],
            'review_note' => $this->review_note,
            'outcome' => $this->outcome?->value,
            'member' => self::member($this->user),
            'product' => $this->product === null ? null : ['id' => $this->product->id, 'slug' => $this->product->slug, 'name' => $this->product->name],
            'observed' => $details['observed'] ?? null,
            'threshold' => $details['threshold'] ?? null,
        ];
    }

    /** @return array{id: string, handle: string|null, name: string, risk_score: int, suspended: bool, deleted: bool}|null */
    public static function member(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id, 'handle' => $user->handle, 'name' => $user->name, 'risk_score' => $user->risk_score,
            'suspended' => $user->isSuspended(), 'deleted' => $user->isDeleted(),
        ];
    }
}
