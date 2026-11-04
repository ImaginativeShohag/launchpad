<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\Utf8;
use App\Support\CleanText;
use Closure;
use Illuminate\Support\Str;

/** Void a member's votes on unfrozen days (all, or only on one product): admin only. */
class VoidMemberVotesRequest extends StaffRiskAdminRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => CleanText::clean($this->input('reason'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['bail', 'required', 'string', 'min:5', 'max:255', new Utf8],
            // Not nullable: an empty or null filter is a client bug, never "everything".
            'product_id' => ['sometimes', 'bail', 'string', new Utf8, function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Str::isUlid($value)) {
                    $fail('The product id is not valid.');
                }
            }],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function productId(): ?string
    {
        $id = $this->validated('product_id');

        return is_string($id) ? $id : null;
    }
}
