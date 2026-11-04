<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\ProductSlug;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreateProductRequest extends FormRequest
{
    /** Only strings are normalised; anything else reaches the validator untouched and is rejected there. */
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        $this->merge([
            'name' => CleanText::clean($this->input('name')),
            'slug' => is_string($slug) ? Str::lower(trim($slug)) : $slug,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', new Utf8, 'regex:/[\p{L}\p{N}]/u'],
            'slug' => ['required', new ProductSlug],
        ];
    }
}
