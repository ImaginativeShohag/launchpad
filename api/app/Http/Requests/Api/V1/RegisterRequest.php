<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\Handle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $handle = $this->input('handle');

        $this->merge([
            'email' => is_string($email) ? User::normaliseEmail($email) : $email,
            'handle' => is_string($handle) ? Str::lower(trim($handle)) : $handle,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')],
            // Production hashes with argon2id (no 72-byte bcrypt truncation), so 200 is a safe cap; tests run bcrypt with rounds=4.
            'password' => ['required', 'string', 'max:200', Password::defaults()],
            'handle' => ['required', 'string', new Handle],
        ];
    }
}
