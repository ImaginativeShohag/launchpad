<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChangeRoleRequest extends FormRequest
{
    /** Authorised before validation, so outsiders learn nothing about the rules. */
    public function authorize(): bool
    {
        return Gate::allows('changeRole', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Admins are only created with `php artisan launchpad:make-admin`.
        return ['role' => ['required', 'string', Rule::in([Role::Member->value, Role::Reviewer->value])]];
    }
}
