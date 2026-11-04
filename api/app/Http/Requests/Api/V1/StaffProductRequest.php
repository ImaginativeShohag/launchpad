<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StaffProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', Product::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
