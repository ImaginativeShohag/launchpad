<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => User::normaliseEmail($email)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:255'],
            'token' => ['bail', 'required', 'string', 'max:255'],
            'password' => ['bail', 'required', 'string', 'max:200', Password::defaults()],
        ];
    }
}
