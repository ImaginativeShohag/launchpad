<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes ip_hash or user_agent. The actor is shown as id and public handle only.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'meta' => $this->meta,
            'actor' => $this->actor === null ? null : ['id' => $this->actor->id, 'handle' => $this->actor->handle],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
