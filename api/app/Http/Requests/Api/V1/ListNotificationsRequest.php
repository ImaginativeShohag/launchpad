<?php

namespace App\Http\Requests\Api\V1;

class ListNotificationsRequest extends CursorListRequest
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
        return ['created_at' => 'date', 'id' => 'uuid'];
    }

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        // `unread=1` filters; `0`, absent and empty (null) mean all.
        return ['unread' => ['sometimes', 'nullable', 'bail', 'string', 'in:0,1']];
    }

    public function unreadOnly(): bool
    {
        return $this->validated('unread') === '1';
    }
}
