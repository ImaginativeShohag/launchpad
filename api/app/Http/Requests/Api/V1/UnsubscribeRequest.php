<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EmailType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The signed query of an unsubscribe link. The body is ignored (a mail client's one-click POST sends `List-Unsubscribe=One-Click`). */
class UnsubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the signature authorises
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user' => ['bail', 'required', 'string', 'ulid'],
            // A mandatory type with a valid signature is a 422: it can never be switched off.
            'type' => ['bail', 'required', 'string', Rule::in(array_map(fn (EmailType $type) => $type->value, EmailType::preferenceTypes()))],
        ];
    }

    public function userId(): string
    {
        return (string) $this->validated('user');
    }

    public function emailType(): EmailType
    {
        return EmailType::from((string) $this->validated('type'));
    }
}
