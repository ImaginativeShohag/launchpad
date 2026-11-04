<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\AvatarImage;
use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `bail`: the header check must never run on something that failed the cheap checks.
            'avatar' => ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', new AvatarImage],
        ];
    }
}
