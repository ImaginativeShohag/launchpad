<?php

use Illuminate\Support\Str;

it('accepts a valid cursor of each search sort', function () {
    // Given a cursor for each of the three search shapes
    $ulid = (string) Str::ulid();
    $relevance = searchCursorOf(['h' => 'abcdef012345', 'score' => '0.0000', 'votes_count' => 0, 'id' => $ulid, '_pointsToNextItems' => true]);
    $votes = searchCursorOf(['h' => 'abcdef012345', 'votes_count' => 12, 'launch_date' => '2026-10-20', 'id' => $ulid, '_pointsToNextItems' => true]);
    $newest = searchCursorOf(['h' => 'abcdef012345', 'launched_at' => '2026-10-20 12:00:00+00:00', 'id' => $ulid, '_pointsToNextItems' => true]);
    $micro = searchCursorOf(['h' => 'abcdef012345', 'launched_at' => '2026-10-20T12:00:00.123456Z', 'id' => $ulid, '_pointsToNextItems' => true]);

    // When validated against their shapes
    // Then all pass
    expect(searchCursorPasses($relevance, SEARCH_RELEVANCE_SHAPE))->toBeTrue()
        ->and(searchCursorPasses($votes, SEARCH_VOTES_SHAPE))->toBeTrue()
        ->and(searchCursorPasses($newest, SEARCH_NEWEST_SHAPE))->toBeTrue()
        ->and(searchCursorPasses($micro, SEARCH_NEWEST_SHAPE))->toBeTrue();
});

it('refuses a bad decimal score', function (mixed $score) {
    // Given a relevance cursor whose score has the wrong type or shape
    $cursor = searchCursorOf(searchRelevancePayload(['score' => $score]));

    // When / Then
    expect(searchCursorPasses($cursor, SEARCH_RELEVANCE_SHAPE))->toBeFalse();
})->with([
    'float' => [3.5],
    'whole float' => [3.0],
    'int' => [3],
    'string int' => ['3'],
    'too many places' => ['3.00000'],
    'too few places' => ['3.00'],
    'negative' => ['-1.0000'],
    'two digits' => ['10.0000'],
    'empty' => [''],
    'null' => [null],
    'array' => [['3.0000']],
    'trailing newline' => ["3.0000\n"],
    'exponent' => ['3.0e+4'],
]);

it('refuses a whole float score written as 3.0 in the raw JSON', function () {
    // Given a cursor whose score was written as a JSON number (json_encode would hide the float, so write the JSON by hand)
    $json = '{"h":"abcdef012345","score":3.0000,"votes_count":4,"id":"'.Str::ulid().'","_pointsToNextItems":true}';
    $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    // When / Then
    expect(searchCursorPasses($cursor, SEARCH_RELEVANCE_SHAPE))->toBeFalse();
});

it('refuses a bad day', function (mixed $day) {
    // Given a votes cursor whose launch_date is not a plain Y-m-d day
    $cursor = searchCursorOf(['h' => 'abcdef012345', 'votes_count' => 1, 'launch_date' => $day, 'id' => (string) Str::ulid(), '_pointsToNextItems' => true]);

    // When / Then
    expect(searchCursorPasses($cursor, SEARCH_VOTES_SHAPE))->toBeFalse();
})->with([
    'impossible day' => ['2026-02-31'],
    'with time' => ['2026-10-20 00:00:00'],
    'compact' => ['20261020'],
    'short' => ['2026-1-2'],
    'year zero' => ['0000-10-20'],
    'month 13' => ['2026-13-01'],
    'trailing newline' => ["2026-10-20\n"],
    'int' => [20261020],
    'null' => [null],
    'array' => [['2026-10-20']],
]);

it('refuses a bad hash', function (mixed $hash) {
    // Given a relevance cursor with a bad bound-search hash
    $cursor = searchCursorOf(searchRelevancePayload(['h' => $hash]));

    // When / Then
    expect(searchCursorPasses($cursor, SEARCH_RELEVANCE_SHAPE))->toBeFalse();
})->with([
    'uppercase' => ['ABCDEF012345'],
    'eleven' => ['abcdef01234'],
    'thirteen' => ['abcdef0123456'],
    'not hex' => ['abcdefghijkl'],
    'int' => [123456789012],
    'null' => [null],
    'array' => [['abcdef012345']],
    'trailing newline' => ["abcdef012345\n"],
]);

it('refuses wrong key order, a missing pointer, an extra key and a wrong id', function (array $payload) {
    // Given a tampered relevance cursor
    // When / Then
    expect(searchCursorPasses(searchCursorOf($payload), SEARCH_RELEVANCE_SHAPE))->toBeFalse();
})->with(function () {
    $ulid = (string) Str::ulid();

    return [
        'wrong key order' => [['score' => '3.0000', 'h' => 'abcdef012345', 'votes_count' => 4, 'id' => $ulid, '_pointsToNextItems' => true]],
        'missing pointer' => [['h' => 'abcdef012345', 'score' => '3.0000', 'votes_count' => 4, 'id' => $ulid]],
        'extra key' => [['h' => 'abcdef012345', 'score' => '3.0000', 'votes_count' => 4, 'id' => $ulid, 'x' => 1, '_pointsToNextItems' => true]],
        'missing hash' => [['score' => '3.0000', 'votes_count' => 4, 'id' => $ulid, '_pointsToNextItems' => true]],
        'not a ulid' => [['h' => 'abcdef012345', 'score' => '3.0000', 'votes_count' => 4, 'id' => 'nope', '_pointsToNextItems' => true]],
        'string votes' => [['h' => 'abcdef012345', 'score' => '3.0000', 'votes_count' => '4', 'id' => $ulid, '_pointsToNextItems' => true]],
        'pointer not bool' => [['h' => 'abcdef012345', 'score' => '3.0000', 'votes_count' => 4, 'id' => $ulid, '_pointsToNextItems' => 1]],
    ];
});

it('refuses a cursor of one search sort used with another sort', function () {
    // Given a votes cursor
    $votes = searchCursorOf(['h' => 'abcdef012345', 'votes_count' => 12, 'launch_date' => '2026-10-20', 'id' => (string) Str::ulid(), '_pointsToNextItems' => true]);

    // When validated against the newest and the relevance shapes
    // Then it fails both
    expect(searchCursorPasses($votes, SEARCH_NEWEST_SHAPE))->toBeFalse()->and(searchCursorPasses($votes, SEARCH_RELEVANCE_SHAPE))->toBeFalse();
});
