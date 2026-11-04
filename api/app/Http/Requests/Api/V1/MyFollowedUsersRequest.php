<?php

namespace App\Http\Requests\Api\V1;

class MyFollowedUsersRequest extends CursorListRequest
{
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
        return ['created_at' => 'date', 'followed_id' => 'ulid'];
    }
}
