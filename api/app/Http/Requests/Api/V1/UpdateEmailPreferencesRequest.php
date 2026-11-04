<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EmailType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `preferences` is a non-empty JSON object keyed by optional mail type, every value a real JSON boolean. `array` accepts
 * a list, and `boolean` accepts 1 and "1", so keys and value types are checked by hand on the raw input.
 */
class UpdateEmailPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route guards do the work
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['preferences' => ['bail', 'required', 'array', 'min:1']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $preferences = $this->input('preferences');

            if (! is_array($preferences) || $validator->errors()->has('preferences')) {
                return;
            }

            $allowed = array_map(fn (EmailType $type) => $type->value, EmailType::preferenceTypes());
            foreach ($preferences as $key => $value) {
                if (! is_string($key) || ! in_array($key, $allowed, true)) {
                    $validator->errors()->add('preferences', 'Only the optional email types can be set.');

                    return;
                }
                if (! is_bool($value)) {
                    $validator->errors()->add("preferences.{$key}", 'The value must be true or false.');
                }
            }
        }];
    }

    /** @return array<string, bool> */
    public function changes(): array
    {
        /** @var array<string, bool> $changes */
        $changes = $this->validated('preferences');

        return $changes;
    }
}
