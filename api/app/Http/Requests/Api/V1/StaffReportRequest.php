<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The report queue and one report target. Staff only, judged before anything is looked up or validated. */
class StaffReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', Report::class);
    }

    /**
     * Query string only: a JSON body on a GET never reaches the rules.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // The detail route carries its type in the path (and answers 404 for a bad one); only the queue filters by query.
        return $this->route('type') !== null ? [] : ['type' => ['sometimes', 'bail', 'string', Rule::in(['comment', 'product'])]];
    }

    /** @return 'comment'|'product'|null */
    public function type(): ?string
    {
        $type = $this->validated('type');

        return $type === 'comment' || $type === 'product' ? $type : null;
    }
}
