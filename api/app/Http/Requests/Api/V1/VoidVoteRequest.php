<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\Utf8;
use App\Support\CleanText;

/** Void one vote: admin only, a plain-text reason of 5 to 255 characters. */
class VoidVoteRequest extends StaffRiskAdminRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => CleanText::clean($this->input('reason'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['bail', 'required', 'string', 'min:5', 'max:255', new Utf8]];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
