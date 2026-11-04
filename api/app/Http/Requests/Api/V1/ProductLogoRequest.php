<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\AvatarImage;

class ProductLogoRequest extends OwnedProductRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `bail`: the header check must never run on something that failed the cheap checks.
            'logo' => ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', new AvatarImage(minSide: 128)],
        ];
    }
}
