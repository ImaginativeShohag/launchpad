<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SuspendUserRequest extends FormRequest
{
    /** Admin only, judged before the handle is looked up and before validation: outsiders learn nothing about members or rules. */
    public function authorize(): bool
    {
        return Gate::allows('suspend', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => CleanText::clean($this->input('reason'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['bail', 'required', 'string', 'max:500', new Utf8]];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
