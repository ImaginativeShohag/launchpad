<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReviewDecision;
use App\Rules\Utf8;
use App\Support\CleanText;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewDecisionRequest extends StaffProductRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('note')) {
            $this->merge(['note' => CleanText::cleanMultilineOrNull($this->input('note'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(ReviewDecision::values())],
            'note' => ['nullable', 'string', 'max:2000', new Utf8],
            // The `submitted_at` the reviewer saw (microsecond ISO string, as the queue and detail return it).
            'submitted_at' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/'],
        ];
    }

    /**
     * Rejections and change requests are shown to the founder, so they need a reason.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $decision = $this->input('decision');

            if (is_string($decision) && ReviewDecision::tryFrom($decision)?->needsNote() === true
                && blank($this->input('note')) && ! $validator->errors()->has('note')) {
                $validator->errors()->add('note', 'A note is required for this decision.');
            }
        }];
    }
}
