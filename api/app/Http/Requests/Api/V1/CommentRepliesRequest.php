<?php

namespace App\Http\Requests\Api\V1;

/** The replies of one thread: `per_page` (20 / 50) and a shape-checked cursor. Reads the query string only. */
class CommentRepliesRequest extends CursorListRequest
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

    protected function cursorShape(): array
    {
        return ['created_at' => 'date', 'id' => 'ulid'];
    }
}
