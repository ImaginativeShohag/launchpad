<?php

namespace App\Http\Requests\Api\V1;

class UserProductsRequest extends CursorListRequest
{
    public function authorize(): bool
    {
        return true; // public
    }

    protected function cursorShape(): array
    {
        return ['launched_at' => 'date', 'id' => 'ulid'];
    }
}
