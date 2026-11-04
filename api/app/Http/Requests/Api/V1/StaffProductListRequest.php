<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The staff product list: `status` (anything but draft), `q`, `per_page`, cursor. Reviewers and admins, judged first. */
class StaffProductListRequest extends CursorListRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return Gate::allows('review', Product::class);
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    protected function cursorShape(): array
    {
        return ['updated_at' => 'date', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return [
            'status' => ['sometimes', 'bail', 'string', Rule::in(array_values(array_diff(ProductStatus::values(), [ProductStatus::Draft->value])))],
            'q' => ['sometimes', 'bail', 'string', 'min:2', 'max:100', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
        ];
    }

    public function status(): ?ProductStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? ProductStatus::tryFrom($value) : null;
    }

    public function search(): ?string
    {
        $value = $this->validated('q');

        return is_string($value) ? $value : null;
    }
}
