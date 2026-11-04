<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\AvatarImage;
use App\Rules\Utf8;
use App\Support\CleanText;

class ProductScreenshotRequest extends OwnedProductRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('alt_text')) {
            $this->merge(['alt_text' => CleanText::cleanOrNull($this->input('alt_text'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'screenshot' => ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096', new AvatarImage(minSide: 320)],
            'alt_text' => ['nullable', 'string', 'max:200', new Utf8],
        ];
    }
}
