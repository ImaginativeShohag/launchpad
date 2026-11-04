<?php

namespace App\Support;

/** Normalises free text typed by members. Only strings are touched; everything else is left for the validator to reject. */
final class CleanText
{
    /**
     * Control characters, line/paragraph separators, and characters that spoof or hide text: bidi embeddings,
     * overrides and isolates, LRM/RLM, the Arabic letter mark, zero-width space, word joiner, BOM, and
     * "invisible letters" that render blank (Hangul fillers, soft hyphen, combining grapheme joiner, Mongolian
     * selectors, Khmer inherent vowels, invisible operators, interlinear annotation, object replacement,
     * braille blank). ZWNJ (U+200C), ZWJ (U+200D) and variation selectors (U+FE00-FE0F) are kept on purpose:
     * Persian and Bengali words and emoji sequences need them. Tag characters (U+E0000-E007F) are stripped,
     * which degrades the rare subdivision-flag emoji (England, Scotland, Wales) to a plain black flag; accepted for names.
     */
    private const STRIP = '/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}\x{200B}\x{2060}\x{FEFF}'
        .'\x{115F}\x{1160}\x{3164}\x{FFA0}\x{00AD}\x{034F}\x{180B}-\x{180E}\x{17B4}\x{17B5}'
        .'\x{2061}-\x{2064}\x{206A}-\x{206F}\x{FFF9}-\x{FFFC}\x{2800}\x{E0000}-\x{E007F}]/u';

    /**
     * Strips control characters (and the Unicode line/paragraph separators), then trims Unicode whitespace.
     * Invalid UTF-8 is returned unchanged so that {@see self::validUtf8()} can reject it.
     */
    public static function clean(mixed $value): mixed
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $stripped = preg_replace(self::STRIP, '', $value);
        $trimmed = $stripped === null ? null : preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $stripped);

        return $trimmed ?? $value;
    }

    /** Like {@see self::clean()}, but whitespace-only text becomes null. */
    public static function cleanOrNull(mixed $value): mixed
    {
        $clean = self::clean($value);

        return $clean === '' ? null : $clean;
    }

    /**
     * Like {@see self::clean()} but keeps line breaks (descriptions, review notes). CRLF and Unicode line separators
     * become "\n", every line is cleaned on its own, and three or more newlines in a row shrink to one blank line.
     * Non-strings and invalid UTF-8 are returned unchanged for the validator to reject.
     */
    public static function cleanMultiline(mixed $value): mixed
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $lines = preg_split('/\R/u', $value);

        if ($lines === false) {
            return $value;
        }

        $text = implode("\n", array_map(fn (string $line) => (string) self::clean(str_replace("\t", ' ', $line)), $lines));
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text) ?? $text;
    }

    /** Like {@see self::cleanMultiline()}, but whitespace-only text becomes null. */
    public static function cleanMultilineOrNull(mixed $value): mixed
    {
        $clean = self::cleanMultiline($value);

        return $clean === '' ? null : $clean;
    }

    public static function validUtf8(mixed $value): bool
    {
        return ! is_string($value) || mb_check_encoding($value, 'UTF-8');
    }
}
