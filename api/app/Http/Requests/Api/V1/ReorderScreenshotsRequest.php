<?php

namespace App\Http\Requests\Api\V1;

class ReorderScreenshotsRequest extends OwnedProductRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'max:20'],
            'ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ];
    }
}
