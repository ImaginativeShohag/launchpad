<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the preference list: `{type, enabled}`. The resource wraps the plain arrays the action returns.
 *
 * @mixin \stdClass
 * @property string $type
 * @property bool $enabled
 */
class EmailPreferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['type' => $this->resource['type'], 'enabled' => $this->resource['enabled']];
    }
}
