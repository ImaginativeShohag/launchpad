<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bell row. `data` is returned exactly as stored (ids, a message key, cleaned params, an app-relative href); the
 * client renders `params` as text, never as HTML.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            // params is always a JSON object: an empty PHP array would encode as [].
            'data' => [...$this->data, 'params' => (object) ($this->data['params'] ?? [])],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
