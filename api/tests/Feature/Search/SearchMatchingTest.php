<?php

use App\Actions\Search\SearchProducts;
use App\Actions\Search\SearchQuery;
use App\Enums\SearchSort;
use App\Models\Topic;

it('needs every token: whole words first, the last token as a prefix', function () {
    // Given three products whose names are far from the words typed (the typo arm is a fuzzy match on the name)
    searchLive('Zebra', 'launch pad');
    searchLive('Yak', 'launch things quickly');
    searchLive('Okapi', 'launchpad');

    // When the visitor types two words, a prefix, and an earlier word as a prefix
    // Then both tokens must match, the last token is a prefix, and an earlier token is a whole word only
    expect(searchNames('launch pad'))->toBe(['Zebra']);
    expect(searchNames('laun'))->toHaveCount(3);
    expect(searchNames('launchpad lau'))->toBe(['Okapi']);
    expect(searchNames('lau launchpad'))->toBe([]);
    expect(searchNames('launch pa'))->toBe(['Zebra']);
});

it('searches the name, the tagline and the description, but not topic names, maker names or links', function () {
    // Given products with the word in each field, a maker called Zebra and a product in a topic called Zebra
    searchLive('Alpha', 'quokka tagline');
    searchLive('Beta', null, 'about a quokka');
    searchLive('Quokka Tracker');
    $byMaker = searchLive('Gamma', 'plain');
    $byMaker->maker->forceFill(['name' => 'Zebra Maker'])->save();
    $inTopic = searchLive('Delta', 'plain');
    searchTopicLink($inTopic, Topic::factory()->create(['name' => 'Zebra Topic', 'slug' => 'zebra-topic']));

    // When searching for each word
    // Then name, tagline and description match; maker and topic names do not (the topic filter finds the topic's products)
    expect(searchNames('quokka'))->toContain('Alpha', 'Beta', 'Quokka Tracker');
    expect(searchNames('zebra'))->toBe([]);
    expect(searchNames(null, 'zebra-topic'))->toBe(['Delta']);
});

it('ignores case and accents and applies NFKC', function (string $stored, string $typed) {
    // Given a product
    searchLive($stored);

    // When the visitor types another form of the same word
    // Then it is found
    expect(searchNames($typed))->toBe([$stored]);
})->with([
    'accent to plain' => ['Café', 'cafe'],
    'plain to accent' => ['Cafe', 'café'],
    'upper case' => ['Café', 'CAFE'],
    'accent to accent' => ['Café', 'CAFÉ'],
    'full width' => ['Launch', "\u{FF2C}\u{FF21}\u{FF35}\u{FF2E}\u{FF23}\u{FF28}"],
    'ligature' => ['Fine Tune', "\u{FB01}ne"],
    'composed to decomposed ya' => ["\u{09DF}ক", "\u{09AF}\u{09BC}ক"],
    'decomposed to composed ya' => ["\u{09AF}\u{09BC}ক", "\u{09DF}ক"],
    'greek' => ['ΑΒΓ Delta', 'αβγ'],
    'cyrillic' => ['ПРИВЕТ Мир', 'привет'],
]);

it('finds Bengali and Persian text by whole word and by prefix', function () {
    // Given Bengali and Persian products, one with a ZWNJ inside a word
    searchLive('বাংলা কলম');
    searchLive('Pen', null, 'একটি কলম দিয়ে লেখা');
    searchLive("\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}");
    searchLive('کتاب');

    // When searched by a whole word, a prefix, a mixed query, the whole ZWNJ word and a prefix ending after the ZWNJ
    // Then they are found
    expect(searchNames('কলম'))->toHaveCount(2);
    expect(searchNames('কল'))->toHaveCount(2);
    expect(searchNames('বাংলা'))->toBe(['বাংলা কলম']);
    expect(searchNames("\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}"))->toHaveCount(1);
    expect(searchNames("\u{0645}\u{06CC}\u{200C}\u{062E}"))->toHaveCount(1);
    expect(searchNames('کت'))->toBe(['کتاب']);
});

it('needs both tokens of a mixed-script query and ignores one-letter tokens', function () {
    // Given a product with Bengali and Latin words in its tagline, and an unrelated one
    searchLive('Zebra', 'কলম pen');
    searchLive('Launchpad');

    // When searched with both words, and with one-letter tokens around a word
    // Then both must match, and the one-letter tokens are dropped
    expect(searchNames('কলম pen'))->toBe(['Zebra']);
    expect(searchNames('কলম xyz'))->toBe([]);
    expect(searchNames('a launchpad'))->toBe(searchNames('launchpad'));
});

it('finds a name typo by trigrams, but not a transposition or a description typo', function (string $typed, bool $found) {
    // Given a product named Launchpad and one whose description has a long word
    searchLive('Launchpad');
    searchLive('Zebra', null, 'a wonderful extraordinary thing');

    // When the visitor mistypes
    $names = searchNames($typed);

    // Then name typos (deletions, insertions, substitutions) find it; a transposition and a description typo do not
    expect(in_array('Launchpad', $names, true))->toBe($found);
    expect($names)->not->toContain('Zebra');
})->with([
    'deletion' => ['lanchpad', true],
    'missing letter' => ['launcpad', true],
    'dropped letter' => ['lunchpad', true],
    'short deletion' => ['launchpd', true],
    'transposition (documented limit)' => ['lauchnpad', false],
    'description typo' => ['extraordinry', false],
]);

it('does not use the trigram arm under three characters, and ranks a name tail after real matches', function () {
    // Given Launchpad and a product with the word pad
    searchLive('Launchpad');
    searchLive('Notepad Pro', 'a pad for notes');
    searchLive('Pad Thai', 'food');

    // When two letters are typed, and three
    // Then two letters only prefix-match words (la finds Launchpad by prefix, not by trigram: xl finds nothing)
    expect(searchNames('la'))->toBe(['Launchpad']);
    expect(searchNames('xl'))->toBe([]);

    // And pad matches Launchpad only through the name tail, after the real word matches
    $pad = searchNames('pad');
    expect(array_slice($pad, 0, 2))->toEqualCanonicalizing(['Notepad Pro', 'Pad Thai'])->and(end($pad))->toBe('Launchpad');
});

it('browses a topic with no query, most voted first', function () {
    // Given a topic with two products and a product outside it
    $topic = Topic::factory()->create(['slug' => 'tools']);
    $low = searchLive('Low', null, null, ['votes_count' => 1]);
    $high = searchLive('High', null, null, ['votes_count' => 9]);
    searchLive('Outside', null, null, ['votes_count' => 50]);
    searchTopicLink($low, $topic);
    searchTopicLink($high, $topic);

    // When the topic is searched without a query
    // Then its visible products come most voted first
    expect(searchNames(null, 'tools'))->toBe(['High', 'Low']);
});

it('answers an empty page for a token that folds to nothing, without an error', function () {
    // Given a product
    searchLive('Launchpad');

    // When tokens whose fold is empty or odd reach the action directly
    $page = app(SearchProducts::class)->handle(SearchQuery::fromString('½½'), null, SearchSort::Relevance, 20, null);

    // Then the page is empty or harmless, never an exception
    expect($page->products->count())->toBeLessThanOrEqual(1);
});

it('applies the typo arm to a single word only: more tokens must all match in the full text', function () {
    // Given a product named Launchpad
    searchLive('Launchpad');

    // When a typo, and a name followed by an unrelated word, are searched
    // Then the single-word typo finds it and the two-token query does not
    expect(searchNames('lanchpad'))->toBe(['Launchpad']);
    expect(searchNames('launchpad zzzz'))->toBe([]);
    expect(searchNames('lanchpad zzzz'))->toBe([]);
});

it('ranks a lone one- or two-letter prefix by the tier only, so votes and id decide', function () {
    // Given matches for "qu" with different votes, and no ts_rank_cd in the statement
    $low = searchLive('Quokka', null, null, ['votes_count' => 1]);
    $high = searchLive('Quill', null, null, ['votes_count' => 9]);
    $mid = searchLive('Quartz', null, null, ['votes_count' => 4]);

    // When searched, and paged one at a time
    $sql = searchCaptureMainSql(fn () => searchRun('qu'));
    $paged = searchPageThrough('qu', null, null, 1);

    // Then the order is by votes, the cheap score is used, and the cursor pages through everything
    expect(searchNames('qu'))->toBe(['Quill', 'Quartz', 'Quokka']);
    expect($sql)->not->toContain('ts_rank_cd')->and(searchCaptureMainSql(fn () => searchRun('quokka')))->toContain('ts_rank_cd');
    expect($paged)->toBe([$high->id, $mid->id, $low->id]);
});
