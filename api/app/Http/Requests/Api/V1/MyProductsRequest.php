<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductStatus;
use Illuminate\Validation\Rule;

class MyProductsRequest extends CursorListRequest
{
    public function authorize(): bool
    {
        return true; // the route is behind auth; the query is scoped to the caller
    }

    protected function cursorShape(): array
    {
        return ['created_at' => 'date', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return ['status' => ['sometimes', 'string', Rule::in(ProductStatus::values())]];
    }
}
