<?php

use App\Support\CleanText;

it('keeps line breaks, strips hidden characters per line and collapses long blank runs', function () {
    // Given text with CRLF, a bidi override, a zero-width space and four blank lines
    $raw = "  First line\r\nSecond\u{202E} line\u{200B}\r\n\r\n\r\n\r\nThird  \n";

    // When cleaned
    $clean = CleanText::cleanMultiline($raw);

    // Then breaks survive as \n, hidden characters are gone, blank runs shrink to one blank line
    expect($clean)->toBe("First line\nSecond line\n\nThird");
});

it('leaves non-strings and invalid UTF-8 for the validator, and turns blank text into null', function () {
    // Given values the validator must reject, and whitespace-only text
    // When cleaned
    // Then non-strings come back untouched and blank text is null
    expect(CleanText::cleanMultiline(['x']))->toBe(['x']);
    expect(CleanText::cleanMultiline(12))->toBe(12);
    expect(CleanText::cleanMultiline("bad\xC3\x28"))->toBe("bad\xC3\x28");
    expect(CleanText::cleanMultilineOrNull(" \n \u{200B} \n"))->toBeNull();
    expect(CleanText::cleanMultilineOrNull(null))->toBeNull();
});
