<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Report\ResolveOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ResolveOutcome */
class ResolveOutcomeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'closed' => $this->closed,
            'action' => $this->action->value,
            'type' => $this->type,
            'target_id' => $this->targetId,
            'target_changed' => $this->targetChanged,
        ];
    }
}
