<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Support\Facades\Gate;

class ReviewQueueRequest extends CursorListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', Product::class);
    }

    protected function cursorShape(): array
    {
        return ['submitted_at' => 'date', 'id' => 'ulid'];
    }
}
