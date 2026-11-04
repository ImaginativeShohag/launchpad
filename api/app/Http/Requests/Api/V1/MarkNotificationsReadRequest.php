<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Exactly one of `ids` (1 to 50 UUIDs, a JSON list) or `all` (the JSON boolean true). Both, neither and every odd type
 * is a 422: `array` accepts a keyed object, `boolean` accepts 1 and "1", so those two are checked by hand.
 */
class MarkNotificationsReadRequest extends FormRequest
{
    public const MAX_IDS = 50;

    public function authorize(): bool
    {
        return true; // the route guards do the work
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'bail', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['bail', 'string', 'uuid'],
            'all' => ['sometimes', 'bail'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $hasIds = $this->has('ids');
            $hasAll = $this->has('all');
            $ids = $this->input('ids');

            if ($hasIds && is_array($ids) && ! array_is_list($ids)) {
                $validator->errors()->add('ids', 'The ids must be a list.');
            }

            if ($hasAll && $this->input('all') !== true) {
                $validator->errors()->add('all', 'The all field must be true.');
            }

            if ($hasIds === $hasAll) {
                $validator->errors()->add($hasIds ? 'all' : 'ids', 'Send either ids or all, not both and not neither.');
            }
        }];
    }

    /** @return list<string>|null the distinct, lower-cased ids, or null when `all` was sent */
    public function ids(): ?array
    {
        $ids = $this->validated('ids');

        if (! is_array($ids)) {
            return null;
        }

        return array_values(array_unique(array_map(fn ($id) => is_string($id) ? strtolower($id) : '', $ids)));
    }

    public function markAll(): bool
    {
        return $this->validated('all') === true;
    }
}
