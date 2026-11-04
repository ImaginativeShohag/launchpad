<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\KeysetCursor;
use Illuminate\Foundation\Http\FormRequest;

/** Query parameters shared by every keyset-paginated list: `per_page` and a shape-checked `cursor`. */
abstract class CursorListRequest extends FormRequest
{
    // Read through static::, so a list may override both.
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** @return array<string, 'date'|'ulid'|'int'|'uuid'|'decimal'|'day'|'hash'> the ordering columns of the list, in order */
    abstract protected function cursorShape(): array;

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'between:1,'.static::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'nullable', 'string', new KeysetCursor($this->cursorShape())],
            ...$this->extraRules(),
        ];
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page');

        // Already validated as an integer within bounds.
        return is_numeric($value) ? (int) $value : static::DEFAULT_PER_PAGE;
    }
}
