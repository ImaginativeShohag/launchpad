<?php

namespace App\Actions\Export;

/** Builds RFC 4180 lines by hand (CRLF, a cell with a comma, a quote, CR or LF is quoted, quotes are doubled). */
final class CsvWriter
{
    /** @param  list<mixed>  $cells */
    public static function row(array $cells): string
    {
        $line = [];

        foreach ($cells as $cell) {
            $text = CsvCell::of($cell);
            $line[] = strpbrk($text, ",\"\r\n") === false ? $text : '"'.str_replace('"', '""', $text).'"';
        }

        return implode(',', $line)."\r\n";
    }
}
