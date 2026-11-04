<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Report;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportHistoryRequest extends CursorListRequest
{
    /** Staff only, before the cursor or anything else is validated. */
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

    protected function cursorShape(): array
    {
        return ['handled_at' => 'date', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return ['type' => ['sometimes', 'bail', 'string', Rule::in(['comment', 'product'])]];
    }

    /** @return 'comment'|'product'|null */
    public function type(): ?string
    {
        $type = $this->validated('type');

        return $type === 'comment' || $type === 'product' ? $type : null;
    }
}
