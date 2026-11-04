<?php

namespace App\Actions\Export;

use Carbon\CarbonInterface;

/**
 * One CSV cell as text. Strings are neutralised against spreadsheet formula injection (a cell that starts with `=`, `+`,
 * `-`, `@`, a tab, CR or LF, or whose first non-blank character is one of the four, gets a leading apostrophe); numbers, booleans and instants are written as such and
 * never go through that rule, so a legitimate -3 stays -3. Quoting is CsvWriter's job.
 */
final class CsvCell
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function text(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // A formula can hide behind leading blanks, so the first character that is not space, tab, CR or LF counts too.
        $first = ltrim($value, " \t\r\n");

        return in_array($value[0], self::TRIGGERS, true) || ($first !== '' && in_array($first[0], ['=', '+', '-', '@'], true)) ? "'".$value : $value;
    }

    public static function of(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            $value instanceof CarbonInterface => $value->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            is_string($value) => self::text($value),
            default => throw new \InvalidArgumentException('A CSV cell holds a scalar or an instant.'),
        };
    }

    /** A plain decimal: `.` as the separator, never scientific notation, no trailing zeros. */
    private static function number(float $value): string
    {
        if (! is_finite($value)) {
            return '';
        }

        $text = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
