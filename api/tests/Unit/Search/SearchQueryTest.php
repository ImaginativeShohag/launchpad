<?php

use App\Actions\Search\SearchQuery;

it('splits a query into tokens', function (string $input, array $expected) {
    // Given a typed query
    // When it is parsed
    $tokens = searchTokens($input);

    // Then the tokens are the words of two or more characters
    expect($tokens)->toBe($expected);
})->with([
    'space' => ['launch pad', ['launch', 'pad']],
    'hyphen' => ['Launch-Pad', ['launch', 'pad']],
    'email' => ['a@b.co', ['co']],
    'version' => ['v2.1', ['v2']],
    'repeated separators' => ["  launch \t\n pad  ", ['launch', 'pad']],
    'digits' => ['2026 launch', ['2026', 'launch']],
    'duplicates are kept' => ['pad pad', ['pad', 'pad']],
    'newline is a word break' => ["launch\npad", ['launch', 'pad']],
    'tab is a word break' => ["launch\tpad", ['launch', 'pad']],
    'line separator is a word break' => ["launch\u{2028}pad", ['launch', 'pad']],
    'nul is a word break' => ["launch\0pad", ['launch', 'pad']],
    'no break space' => ["launch\u{00A0}pad", ['launch', 'pad']],
]);

it('yields no token for text without a usable word', function (string $input) {
    // Given a query with nothing searchable in it
    // When it is parsed
    $query = SearchQuery::fromString($input);

    // Then it is empty
    expect($query->tokens())->toBe([])->and($query->isEmpty())->toBeTrue();
})->with([
    'plus signs' => ['c++'],
    'one letter' => ['a'],
    'punctuation' => ['!!'],
    'emoji' => ['😀😀'],
    'marks only' => ["\u{0301}\u{0302}\u{09BC}"],
    'whitespace' => ["   \t "],
    'bidi override alone' => ["\u{202E}"],
    'empty' => [''],
    'only zero width non joiner' => ["\u{200C}\u{200C}"],
]);

it('normalises with NFKC and lower case but keeps accents', function () {
    // Given full-width, ligature, cased and accented text
    // When it is parsed
    // Then the tokens are NFKC and lower case; accents stay (the database folds them)
    expect(searchTokens('ＡＢＣ'))->toBe(['abc'])
        ->and(searchTokens('ﬁne'))->toBe(['fine'])
        ->and(searchTokens('ÀB'))->toBe(['àb'])
        ->and(searchTokens("\u{0130}stanbul"))->toBe(['istanbul'])
        ->and(searchTokens('ΟΔΟΣ'))->toBe(['οδοσ']);
});

it('treats the composed and the decomposed Bengali ya as one token', function () {
    // Given the two Unicode forms of the Bengali letter ya with nukta in a word
    // When they are parsed
    $composed = searchTokens("\u{09DF}ন");
    $decomposed = searchTokens("\u{09AF}\u{09BC}ন");

    // Then the tokens are equal
    expect($composed)->toBe($decomposed)->and($composed)->toHaveCount(1);
});

it('keeps ZWNJ and ZWJ inside a token and trims them at the ends', function () {
    // Given a Persian word with a ZWNJ, a Bengali conjunct with a ZWJ, and stray joiners
    $persian = "می\u{200C}خواهم";
    $bengali = "ক্ষ\u{200D}ত্র";

    // When they are parsed
    // Then each is one token, and a joiner at either end or alone is dropped
    expect(searchTokens($persian))->toBe([$persian])
        ->and(searchTokens($bengali))->toBe([$bengali])
        ->and(searchTokens("\u{200C}{$persian}\u{200D}"))->toBe([$persian])
        ->and(searchTokens("\u{200C} {$persian}"))->toBe([$persian])
        ->and(searchTokens("\u{200D}\u{200C}"))->toBe([]);
});

it('keeps at most 8 tokens of at most 50 characters', function () {
    // Given nine words and a 51 character word
    $nine = implode(' ', ['aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh', 'ii']);
    $long = str_repeat('x', 51);

    // When they are parsed
    // Then the ninth word is dropped and the long word is cut to 50
    expect(searchTokens($nine))->toBe(['aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh'])
        ->and(searchTokens($long))->toBe([str_repeat('x', 50)]);
});

it('trims a joiner that the 50 character cut leaves at the end of a token', function () {
    // Given a 49 letter word, a ZWNJ and more letters, so the cut lands right after the ZWNJ
    $input = str_repeat('x', 49)."\u{200C}xyz";

    // When it is parsed
    // Then the token ends at the last letter
    expect(searchTokens($input))->toBe([str_repeat('x', 49)]);
});

it('bounds a 99 character and a 300 character query exactly', function () {
    // Given 25 three-letter words (99 characters) and 60 character words cut at 300 characters
    $hundred = substr(implode(' ', array_fill(0, 25, 'abc')), 0, 100);
    $long = substr(implode(' ', array_fill(0, 100, str_repeat('y', 60))), 0, 300);

    // When they are parsed
    // Then the first keeps 8 tokens, the second 5 tokens of 50 characters (the fifth is a 56 character remainder)
    expect(mb_strlen($hundred))->toBe(99)->and(searchTokens($hundred))->toBe(array_fill(0, 8, 'abc'))
        ->and(searchTokens($long))->toBe(array_fill(0, 5, str_repeat('y', 50)));
});

it('never lets an operator or a quote through', function (string $input, array $expected) {
    // Given a query made of tsquery operators, quotes, SQL or control characters
    // When it is parsed
    $tokens = searchTokens($input);

    // Then exactly the plain words remain
    expect($tokens)->toBe($expected);
})->with([
    'operators' => ['& | ! : * ( ) < > \' " \\ % _', []],
    'and not' => ['foo & !bar', ['foo', 'bar']],
    'sql' => ["'; DROP TABLE products; --", ['drop', 'table', 'products']],
    'prefix' => [':*', []],
    'prefix inside' => ['a:*b', []],
    'backslash' => ['\\', []],
    'nul byte' => ["foo\0bar", ['foo', 'bar']],
    'bidi inside a word' => ["fo\u{202E}o bar", ['foo', 'bar']],
    'line separator' => ["foo\u{2028}bar", ['foo', 'bar']],
]);

it('splits into whole words and a prefix token', function () {
    // Given a two word query
    $query = SearchQuery::fromString('launch pad');

    // When the parts are read
    // Then all but the last are whole words, the last is the prefix, and the phrase joins them with one space
    expect($query->wholeTokens())->toBe(['launch'])->and($query->prefixToken())->toBe('pad')->and($query->phrase())->toBe('launch pad');
});

it('allows the typo arm from three characters of the phrase', function (string $input, bool $allowed) {
    // Given a query
    // When asked whether the trigram arm is worth running
    $result = SearchQuery::fromString($input)->allowsTypoMatch();

    // Then two letters do not, three or more do
    expect($result)->toBe($allowed);
})->with([
    'ab' => ['ab', false],
    'abc' => ['abc', true],
    'ab cd' => ['ab cd', true],
]);

it('hashes sort, phrase and topic into a stable 12 character token', function () {
    // Given a query
    $query = SearchQuery::fromString('laun');

    // When hashed
    $hash = $query->hash('relevance', 'ai');

    // Then it is 12 hex characters and stable
    expect($hash)->toMatch('/\A[0-9a-f]{12}\z/')->and($query->hash('relevance', 'ai'))->toBe($hash);
});

it('changes the hash with the sort, the topic and the phrase only', function () {
    // Given a base query
    $base = SearchQuery::fromString('laun')->hash('relevance', 'ai');

    // When one input differs
    // Then the hash differs; case and punctuation noise do not matter, and a null topic equals an empty one
    expect(SearchQuery::fromString('laun')->hash('votes', 'ai'))->not->toBe($base)
        ->and(SearchQuery::fromString('laun')->hash('relevance', 'web'))->not->toBe($base)
        ->and(SearchQuery::fromString('laun')->hash('relevance', null))->not->toBe($base)
        ->and(SearchQuery::fromString('pad')->hash('relevance', 'ai'))->not->toBe($base)
        ->and(SearchQuery::fromString('LAUN')->hash('relevance', 'ai'))->toBe($base)
        ->and(SearchQuery::fromString('laun!!')->hash('relevance', 'ai'))->toBe($base)
        ->and(SearchQuery::fromString('laun')->hash('relevance', ''))->toBe(SearchQuery::fromString('laun')->hash('relevance', null));
});

it('hashes a topic-only search with an empty phrase', function () {
    // Given a topic-only search (no query) and a query that parses to nothing
    // When both are hashed
    $none = SearchQuery::hashFor(null, 'votes', 'ai');
    $empty = SearchQuery::hashFor(SearchQuery::fromString('!!'), 'votes', 'ai');

    // Then they agree, and differ from a real phrase
    expect($none)->toBe($empty)->and($none)->not->toBe(SearchQuery::hashFor(SearchQuery::fromString('laun'), 'votes', 'ai'))
        ->and(SearchQuery::hashFor(SearchQuery::fromString('laun'), 'votes', 'ai'))->toBe(SearchQuery::fromString('laun')->hash('votes', 'ai'));
});

it('does not throw on invalid UTF-8 and yields no tokens', function () {
    // Given bytes that are not UTF-8
    // When they are parsed
    $query = SearchQuery::fromString("abc \xC3\x28 def\xFF");

    // Then the class returns an empty query instead of failing
    expect($query->isEmpty())->toBeTrue();
});
