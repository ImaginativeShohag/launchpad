<?php

use App\Actions\Export\CsvCell;
use App\Actions\Export\CsvWriter;
use Carbon\CarbonImmutable;

it('prefixes a string that starts with a formula character with an apostrophe', function (string $value) {
    // Given a string a spreadsheet would run as a formula
    // When it becomes a cell
    // Then it gets the leading apostrophe
    expect(CsvCell::text($value))->toBe("'".$value)->and(CsvCell::of($value))->toBe("'".$value);
})->with(['=1+1', '+x', '-x', '@x', "\tx", "\rx", "\nx", '=cmd|\' /C calc\'!A0', '-5', ' =1+1', '  -x', "\t @x", " \r\n+1", "\n=2"]);

it('leaves other strings alone, including a leading space before a harmless character', function (string $value) {
    // Given a harmless string
    // When it becomes a cell
    // Then it is unchanged
    expect(CsvCell::text($value))->toBe($value);
})->with(['plain', ' a=1', '   ', 'a=b', 'x-y', '', '1-2', "a\tb", "'quoted"]);

it('writes numbers as numbers: a legitimate negative stays numeric', function () {
    // Given numbers
    // When they become cells
    // Then no apostrophe appears, the decimal separator is a dot and there is no scientific notation
    expect(CsvCell::of(-5))->toBe('-5')->and(CsvCell::of(-0.5))->toBe('-0.5')->and(CsvCell::of(0))->toBe('0')->and(CsvCell::of(12))->toBe('12')
        ->and(CsvCell::of(0.0000001))->toBe('0.0000001')->and(CsvCell::of(1.5))->toBe('1.5')->and(CsvCell::of(2.0))->toBe('2')
        ->and(CsvCell::of(-0.0))->toBe('0')->and(CsvCell::of(123456789012.5))->toBe('123456789012.5')->and(CsvCell::of(0.0791))->toBe('0.0791');
    expect(CsvCell::of(NAN))->toBe('')->and(CsvCell::of(INF))->toBe('');
});

it('writes null, booleans and instants', function () {
    // Given a null, booleans and an instant in another zone
    $instant = CarbonImmutable::parse('2026-10-21 14:30:05', 'Asia/Dhaka');

    // When they become cells
    // Then null is empty, booleans are words and the instant is UTC with a Z
    expect(CsvCell::of(null))->toBe('')->and(CsvCell::of(true))->toBe('true')->and(CsvCell::of(false))->toBe('false')
        ->and(CsvCell::of($instant))->toBe('2026-10-21T08:30:05Z');
});

it('refuses a cell value that is not a scalar or an instant', function () {
    // Given an array
    // When it is made a cell
    // Then it is a programming error
    expect(fn () => CsvCell::of(['x']))->toThrow(InvalidArgumentException::class);
});

it('keeps a ten thousand character cell intact', function () {
    // Given a long string
    $long = str_repeat('ab', 5000);

    // When it is made a cell
    // Then it is whole
    expect(CsvCell::text($long))->toHaveLength(10000)->toBe($long);
});

it('builds RFC 4180 lines: CRLF, quoting only when needed, quotes doubled', function () {
    // Given cells with a comma, a quote, a newline and a CRLF
    // When rows are written
    // Then each is quoted per RFC 4180 and the line ends in CRLF
    expect(CsvWriter::row(['a', 'b']))->toBe("a,b\r\n");
    expect(CsvWriter::row(['a,b']))->toBe("\"a,b\"\r\n");
    expect(CsvWriter::row(['say "hi"']))->toBe("\"say \"\"hi\"\"\"\r\n");
    expect(CsvWriter::row(["line1\nline2"]))->toBe("\"line1\nline2\"\r\n");
    expect(CsvWriter::row(["a\r\nb"]))->toBe("\"a\r\nb\"\r\n");
    expect(CsvWriter::row(['', null, 3, -1.5, true]))->toBe(",,3,-1.5,true\r\n");
    expect(CsvWriter::row(['=1+1,x']))->toBe("\"'=1+1,x\"\r\n");
});

it('round-trips 200 random strings through a CSV parser as the neutralised cells', function () {
    // Given 200 random strings made of the characters that matter
    mt_srand(42);
    $alphabet = ['a', 'Z', '1', ' ', ',', '"', "\n", "\r", "\t", '=', '+', '-', '@', "'", '\\', 'é', '漢', ';'];
    $cells = [];
    for ($i = 0; $i < 200; $i++) {
        $length = mt_rand(1, 12);
        $text = '';
        for ($j = 0; $j < $length; $j++) {
            $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }
        $cells[] = $text;
    }

    foreach ($cells as $text) {
        // When a row with that cell between two plain cells is written and parsed back
        $line = CsvWriter::row(['left', $text, 'right']);
        $parsed = str_getcsv(substr($line, 0, -2), ',', '"', '');

        // Then the cell comes back as its neutralised text
        expect($parsed)->toBe(['left', CsvCell::text($text), 'right']);
    }
});
