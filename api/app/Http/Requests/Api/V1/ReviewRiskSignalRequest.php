<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RiskOutcome;
use App\Models\RiskSignal;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Confirm or dismiss a signal. Reviewers and admins, judged before the signal is looked up and before validation. */
class ReviewRiskSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', RiskSignal::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => CleanText::cleanOrNull($this->input('note'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['bail', 'required', 'string', Rule::in(RiskOutcome::values())],
            'note' => ['nullable', 'string', 'max:255', new Utf8],
        ];
    }

    public function outcome(): RiskOutcome
    {
        return RiskOutcome::from((string) $this->validated('outcome'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
