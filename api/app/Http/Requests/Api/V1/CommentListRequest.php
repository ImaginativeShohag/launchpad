<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CommentSort;
use Illuminate\Validation\Rule;

/** The top-level comment list of a product: `sort`, `per_page` (20 / 50) and a shape-checked cursor. Reads the query string only. */
class CommentListRequest extends CursorListRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true; // public
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /** The cursor of `top` carries the reply count; an unusable `sort` falls back to that shape and fails on the `sort` rule. */
    protected function cursorShape(): array
    {
        $sort = $this->query('sort');

        return $sort === CommentSort::Newest->value
            ? ['created_at' => 'date', 'id' => 'ulid']
            : ['replies_count' => 'int', 'created_at' => 'date', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return ['sort' => ['sometimes', 'bail', 'string', Rule::in(CommentSort::values())]];
    }

    public function sort(): CommentSort
    {
        $sort = $this->validated('sort');

        return is_string($sort) ? (CommentSort::tryFrom($sort) ?? CommentSort::Top) : CommentSort::Top;
    }
}
