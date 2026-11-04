<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A JSON integer. Unlike the `integer` rule it rejects booleans and numeric strings. */
class StrictInteger implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail('The :attribute must be a whole number.');
        }
    }
}
