<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\Utf8;
use App\Support\CleanText;
use Closure;

/** Suspend a member from the risk panel: admin only, a reason, and whether to void their votes (default yes, a real boolean only). */
class SuspendMemberRequest extends StaffRiskAdminRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => CleanText::clean($this->input('reason'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['bail', 'required', 'string', 'min:5', 'max:500', new Utf8],
            // `boolean` would accept 1 and "1"; this endpoint is destructive, so only a JSON true or false counts.
            'void_votes' => ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('The void votes field must be true or false.');
                }
            }],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function voidVotes(): bool
    {
        return $this->validated('void_votes', true) === true;
    }
}
