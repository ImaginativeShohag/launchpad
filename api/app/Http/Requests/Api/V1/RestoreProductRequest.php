<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RestoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('restore', Product::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
