<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Handle implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }

        $handle = strtolower($value);

        if (! preg_match('/^[a-z0-9_]{3,30}\z/', $handle)) {
            $fail('The :attribute may only contain letters, numbers and underscores (3-30 characters).');

            return;
        }

        if (in_array($handle, config('launchpad.reserved_handles'), true)) {
            $fail('This :attribute is reserved.');

            return;
        }

        $taken = User::query()
            ->where('handle', $handle)
            ->when($this->ignoreUserId, fn ($query) => $query->where('id', '!=', $this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('This :attribute is already taken.');
        }
    }
}
