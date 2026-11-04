<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A keyset cursor is client input that goes straight into a WHERE clause, so a tampered one must be a 422, not a
 * database error. This rule decodes it itself: Laravel's `Cursor::fromEncoded()` raises a TypeError on a cursor
 * that decodes to JSON `null` (base64 "bnVsbA") or lacks `_pointsToNextItems`.
 */
class KeysetCursor implements ValidationRule
{
    /** Upper bound of an `int` column in a cursor (reply counts); far above anything real, far below the integer limit. */
    private const MAX_INT = 1_000_000;

    /** @param  array<string, 'date'|'ulid'|'int'|'uuid'|'decimal'|'day'|'hash'>  $shape  the ordering columns, in the order Laravel writes them */
    public function __construct(private readonly array $shape) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->passes($value)) {
            $fail('The :attribute is invalid.');
        }
    }

    /** Whether the value is a cursor of exactly this shape (also used by code that decodes a cursor itself). */
    public function passes(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return false;
        }

        $base64 = strtr($value, '-_', '+/');
        $json = base64_decode(str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '='), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || array_keys($data) !== [...array_keys($this->shape), '_pointsToNextItems'] || ! is_bool($data['_pointsToNextItems'])) {
            return false;
        }

        foreach ($this->shape as $key => $type) {
            $part = $data[$key];

            $valid = match ($type) {
                // A JSON integer only: never a string, a float (3.0 decodes to a float), a bool or null.
                'int' => is_int($part) && $part >= 0 && $part <= self::MAX_INT,
                'ulid' => is_string($part) && Str::isUlid($part),
                // Any UUID version; a malformed id would be a Postgres 22P02 (500) on a uuid column.
                'uuid' => is_string($part) && Str::isUuid($part),
                'date' => is_string($part) && $this->isDate($part),
                // A score rounded to 4 places travels as a STRING ("3.2500"): a JSON float would lose digits. Range 0.0000 to 9.9999.
                'decimal' => is_string($part) && preg_match('/\A\d\.\d{4}\z/', $part) === 1,
                // A calendar day (a `date` column), unlike 'date' which demands a time part.
                'day' => is_string($part) && $this->isDay($part),
                // A short search-binding hash (see SearchQuery::hash()).
                'hash' => is_string($part) && preg_match('/\A[0-9a-f]{12}\z/', $part) === 1,
            };

            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    private function isDay(string $value): bool
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function isDate(string $value): bool
    {
        $pattern = '/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-](\d{2}):\d{2})?\z/';

        if (strlen($value) > 40 || preg_match($pattern, $value, $m) !== 1) {
            return false;
        }

        // Postgres rejects what Carbon silently rolls over (Feb 31, hour 25, year 0, huge offsets): that would be a 500.
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', array_slice($m, 1, 6));

        return $year >= 1
            && checkdate($month, $day, $year)
            && $hour <= 23 && $minute <= 59 && $second <= 59
            && (! isset($m[7]) || (int) $m[7] <= 15);
    }
}
