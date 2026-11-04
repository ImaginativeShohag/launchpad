<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReportAction;
use App\Models\Product;
use App\Models\Report;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResolveReportRequest extends FormRequest
{
    /**
     * Role first, before the route is looked at and before validation, so a member learns nothing (403 for any id and
     * any body). `take_down` is admin only, so a reviewer who asks for it is refused here too. Only then the route
     * parts are judged: an unknown type or a non-ULID id is the plain 404.
     */
    public function authorize(): bool
    {
        if (! Gate::allows('review', Report::class)) {
            return false;
        }

        if ($this->input('action') === ReportAction::TakeDown->value && ! Gate::allows('takeDown', Product::class)) {
            return false;
        }

        abort_unless(in_array($this->route('type'), ['comment', 'product'], true) && Str::isUlid((string) $this->route('id')), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        // Non-strings and invalid UTF-8 pass through unchanged for the rules below to reject; blank text becomes null.
        $this->merge(['note' => CleanText::cleanMultilineOrNull($this->input('note'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', Rule::in(ReportAction::values())],
            'note' => ['sometimes', 'nullable', 'bail', 'string', 'max:500', new Utf8],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $raw = $this->input('action');
            $action = is_string($raw) ? ReportAction::tryFrom($raw) : null;

            if ($action === null) {
                return;
            }

            if ($action->forType() !== null && $action->forType() !== $this->route('type')) {
                $validator->errors()->add('action', "This action does not apply to a {$this->route('type')}.");
            }

            if ($action->needsNote() && ! is_string($this->input('note'))) {
                $validator->errors()->add('note', 'A note is required for this action.');
            }
        }];
    }

    public function action(): ReportAction
    {
        return ReportAction::from((string) $this->validated('action'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
