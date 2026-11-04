<?php

namespace App\Http\Requests\Concerns;

/** Shared `device_name` handling for the endpoints that issue a mobile token. */
trait CleansDeviceName
{
    /** Strips control and invisible edge characters, then trims. Non-strings are left for the validator to reject. */
    protected function cleanDeviceName(): void
    {
        $name = $this->input('device_name');

        if (is_string($name)) {
            // All control characters are removed on purpose, newlines and tabs included.
            $clean = preg_replace('/\p{Cc}/u', '', $name);
            $clean = is_string($clean) ? preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $clean) : null;

            $this->merge(['device_name' => $clean ?? '']);
        }
    }

    /** @return list<string> */
    protected function deviceNameRules(): array
    {
        return ['required', 'string', 'max:100'];
    }
}
