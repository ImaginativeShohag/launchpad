<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestEmailChangeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => User::normaliseEmail($email)]);
        }
    }

    /**
     * Whether the address is already taken is checked in the controller, after the password.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'email:rfc,strict', 'max:255'],
            // Accounts without a password are protected by the recent-login middleware instead.
            'current_password' => [
                'bail',
                Rule::requiredIf(fn () => (bool) $this->user()?->hasPassword()),
                'nullable', 'string', 'max:200',
            ],
        ];
    }
}
