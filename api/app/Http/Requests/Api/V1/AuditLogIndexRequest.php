<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AuditLog;
use App\Rules\KeysetCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AuditLogIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', AuditLog::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actor' => ['sometimes', 'string', 'ulid'],
            'action' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'nullable', 'string', new KeysetCursor(['created_at' => 'date', 'id' => 'ulid'])],
        ];
    }
}
