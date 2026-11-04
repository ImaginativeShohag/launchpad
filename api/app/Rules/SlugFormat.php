<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Lowercase ASCII letters and digits in groups separated by single hyphens, within a length range. */
class SlugFormat implements ValidationRule
{
    public function __construct(private readonly int $min, private readonly int $max) {}

    public static function matches(mixed $value, int $min, int $max): bool
    {
        return is_string($value)
            && strlen($value) >= $min
            && strlen($value) <= $max
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::matches($value, $this->min, $this->max)) {
            $fail("The :attribute must be {$this->min} to {$this->max} lowercase letters, numbers and single hyphens.");
        }
    }
}
