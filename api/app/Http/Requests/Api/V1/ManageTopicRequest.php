<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Topic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ManageTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Topic::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
