<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TakeDownRequest extends FormRequest
{
    /** Admin only, checked before the product is looked up and before validation. */
    public function authorize(): bool
    {
        return Gate::allows('takeDown', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => CleanText::clean($this->input('reason'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500', new Utf8]];
    }
}
