<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReportReason;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateReportRequest extends FormRequest
{
    /** The route guards (auth, active, verified) do the work. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Non-strings and invalid UTF-8 pass through unchanged for the rules below to reject; blank text becomes null.
        $this->merge(['details' => CleanText::cleanMultilineOrNull($this->input('details'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['bail', 'required', 'string', Rule::in(ReportReason::values())],
            'details' => ['sometimes', 'nullable', 'bail', 'string', 'max:1000', new Utf8],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('reason') === ReportReason::Other->value && ! is_string($this->input('details'))) {
                $validator->errors()->add('details', 'Tell us what is wrong when the reason is "other".');
            }
        }];
    }

    public function reason(): ReportReason
    {
        return ReportReason::from((string) $this->validated('reason'));
    }

    public function details(): ?string
    {
        $details = $this->validated('details');

        return is_string($details) ? $details : null;
    }
}
