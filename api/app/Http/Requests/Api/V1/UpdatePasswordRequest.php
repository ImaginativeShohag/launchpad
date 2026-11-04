<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'max:200'],
            'password' => ['bail', 'required', 'string', 'max:200', 'different:current_password', Password::defaults()],
        ];
    }
}
