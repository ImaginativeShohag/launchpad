<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The admin member directory: `q`, `role`, `status`, `per_page` and a cursor. Admins only, judged before anything is validated. */
class AdminUserIndexRequest extends CursorListRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return Gate::allows('listMembers', User::class);
    }

    /**
     * The query string, with a leading `@` (as in a handle) stripped from `q`, so the length rules judge the text that is searched.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $query = $this->query();

        if (isset($query['q']) && is_string($query['q'])) {
            // An emptied value would skip the length rules (empty strings are not validated); null fails `string`.
            $stripped = ltrim($query['q'], '@');
            $query['q'] = $stripped === '' ? null : $stripped;
        }

        return $query;
    }

    protected function cursorShape(): array
    {
        return ['created_at' => 'date', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return [
            'q' => ['sometimes', 'bail', 'string', 'min:2', 'max:100', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
            'role' => ['sometimes', 'bail', 'string', Rule::in(Role::values())],
            'status' => ['sometimes', 'bail', 'string', Rule::in(['active', 'suspended', 'deleted'])],
        ];
    }

    public function search(): ?string
    {
        $value = $this->validated('q');

        return is_string($value) ? $value : null;
    }

    public function role(): ?Role
    {
        $value = $this->validated('role');

        return is_string($value) ? Role::tryFrom($value) : null;
    }

    public function status(): ?string
    {
        $value = $this->validated('status');

        return is_string($value) ? $value : null;
    }
}
