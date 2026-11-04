<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Validates the {date} route segment (and only it: a query-string or body "date" never reaches the action). */
class DayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['date' => $this->route('date')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => [
                'bail', 'required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    [$year, $month, $day] = array_map('intval', explode('-', (string) $value));

                    if (! checkdate($month, $day, $year)) {
                        $fail('The date must be a real calendar date.');
                    }
                },
            ],
        ];
    }

    /** The validated day, Y-m-d. */
    public function day(): string
    {
        return (string) $this->validated('date');
    }
}
