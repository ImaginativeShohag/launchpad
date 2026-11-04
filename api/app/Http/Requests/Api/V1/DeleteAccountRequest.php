<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Google-only accounts have no password; for them the `recent.login` middleware is the proof.
        return [
            'current_password' => [Rule::requiredIf(fn () => (bool) $this->user()?->hasPassword()), 'nullable', 'string', 'max:200'],
        ];
    }
}
