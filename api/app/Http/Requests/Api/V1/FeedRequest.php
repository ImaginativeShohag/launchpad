<?php

namespace App\Http\Requests\Api\V1;

class FeedRequest extends CursorListRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true; // the route guards do the work
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
        return ['launched_at' => 'date', 'id' => 'ulid'];
    }
}
