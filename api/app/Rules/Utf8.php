<?php

namespace App\Rules;

use App\Support\CleanText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses strings that are not valid UTF-8 (the database would reject them with an error). */
class Utf8 implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CleanText::validUtf8($value)) {
            $fail('The :attribute must be valid text.');
        }
    }
}
