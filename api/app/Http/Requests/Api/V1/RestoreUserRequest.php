<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RestoreUserRequest extends FormRequest
{
    /** Admin only, judged before the handle is looked up. */
    public function authorize(): bool
    {
        return Gate::allows('suspend', User::class);
    }

    /**
     * There is no body to validate: whatever is sent is ignored.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
