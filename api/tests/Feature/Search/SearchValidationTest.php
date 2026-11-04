<?php

use App\Actions\Search\SearchCursor;
use App\Actions\Search\SearchQuery;
use App\Models\Topic;
use Illuminate\Support\Str;

beforeEach(function () {
    searchLive('Quokka Tool');
    Topic::factory()->create(['slug' => 'tools']);
});

it('accepts q up to 100 characters (counted in characters) and refuses 101, never truncating', function (string $q, int $status) {
    // Given a query of this length
    // When it is searched
    // Then it is accepted or refused as a whole
    searchApi('q='.urlencode($q))->assertStatus($status);
})->with([
    '99 letters' => [fn () => str_repeat('a', 99), 200],
    '100 letters' => [fn () => str_repeat('a', 100), 200],
    '101 letters' => [fn () => str_repeat('a', 101), 422],
    '100 Bengali letters' => [fn () => str_repeat('ক', 100), 200],
    '101 Bengali letters' => [fn () => str_repeat('ক', 101), 422],
    '300 letters' => [fn () => str_repeat('a', 300), 422],
]);

it('refuses a q with no usable token on q, even with a topic', function (string $q) {
    // Given text without two letters or digits in a row
    // When it is searched, alone and with a topic
    // Then it is a 422 on q
    searchApi('q='.urlencode($q))->assertStatus(422)->assertJsonValidationErrors('q');
    searchApi('topic=tools&q='.urlencode($q))->assertStatus(422)->assertJsonValidationErrors('q');
})->with(['one letter' => ['a'], 'punctuation' => ['!!'], 'emoji' => ['😀'], 'one-letter tokens' => ['a b'], 'operators' => ['& | !']]);

it('treats whitespace-only, empty and bidi-only q as absent', function (string $raw) {
    // Given a blank q
    // When it comes alone, and with a topic
    // Then alone it is 422 (nothing to search), and with a topic the topic search runs
    searchApi('q='.$raw)->assertStatus(422)->assertJsonValidationErrors('q');
    $withTopic = searchApi('topic=tools&q='.$raw)->assertOk();
    expect($withTopic->json('meta.query'))->toBeNull()->and($withTopic->json('meta.sort'))->toBe('votes');
})->with(['empty' => [''], 'spaces' => ['%20%20%20'], 'bidi override' => ['%E2%80%AE'], 'zero-width space' => ['%E2%80%8B']]);

it('keeps eight tokens, silently drops the ninth and refuses invalid UTF-8', function () {
    // Given nine words
    $nine = 'aa bb cc dd ee ff gg hh ii';

    // When searched, and when a malformed byte sequence is sent
    $response = searchApi('q='.urlencode($nine))->assertOk();

    // Then eight tokens are used; invalid UTF-8 is 422
    expect($response->json('meta.query'))->toBe('aa bb cc dd ee ff gg hh');
    searchApi('q=%FF%FEabc')->assertStatus(422)->assertJsonValidationErrors('q');
});

it('treats a repeated q as last-wins and refuses array forms', function () {
    // Given a product
    // When q is repeated, and sent as an array or an object
    $repeated = searchApi('q=zzz&q=quokka')->assertOk();

    // Then the last value wins (documented), and the other forms are 422
    expect($repeated->json('meta.query'))->toBe('quokka');
    searchApi('q[]=quokka')->assertStatus(422);
    searchApi('q[a]=quokka')->assertStatus(422);
});

it('validates topic as an existing slug', function (string $topic) {
    // Given a bad topic
    // When searched
    // Then it is a 422 on topic
    searchApi('q=quokka&topic='.urlencode($topic))->assertStatus(422)->assertJsonValidationErrors('topic');
})->with([
    'unknown' => ['nope'], 'uppercase' => ['Tools'], 'leading hyphen' => ['-x'], 'one character' => ['a'],
    '41 characters' => [fn () => str_repeat('a', 41)], 'with a space' => ['a b'], 'sql' => ["x';drop table products;--"],
]);

it('browses a topic with the default sort when q is absent', function () {
    // Given a known topic
    // When it is the only criterion
    // Then it works
    searchApi('topic=tools')->assertOk();
});

it('validates sort and the rules between sort, q and topic', function () {
    // Given the setup
    // When bad or inconsistent combinations are sent
    // Then each is a 422 on the right field
    searchApi('q=quokka&sort=hot')->assertStatus(422)->assertJsonValidationErrors('sort');
    searchApi('q=quokka&sort[]=x')->assertStatus(422)->assertJsonValidationErrors('sort');
    searchApi('q=quokka&sort[a]=b')->assertStatus(422)->assertJsonValidationErrors('sort');
    searchApi('sort=relevance')->assertStatus(422);
    searchApi('topic=tools&sort=relevance')->assertStatus(422)->assertJsonValidationErrors('sort');
    searchApi('')->assertStatus(422)->assertJsonValidationErrors('q');
    searchApi('sort=newest')->assertStatus(422);
    // And an empty sort means absent
    searchApi('q=quokka&sort=')->assertOk()->assertJsonPath('meta.sort', 'relevance');
});

it('validates per_page as an integer from 1 to 50', function (string $value, int $status) {
    // Given a per_page
    // When searched
    // Then it is accepted or refused
    searchApi('q=quokka&per_page='.urlencode($value))->assertStatus($status);
})->with([
    'zero' => ['0', 422], 'one' => ['1', 200], 'five' => ['5', 200], 'twenty' => ['20', 200], 'fifty' => ['50', 200], 'fifty-one' => ['51', 422],
    'letters' => ['abc', 422], 'decimal' => ['1.5', 422], 'true' => ['true', 422], 'negative' => ['-1', 422], 'empty' => ['', 422],
]);

it('defaults per_page to 20', function () {
    // Given no per_page
    // When searched
    // Then 20 is echoed
    expect(searchApi('q=quokka')->json('meta.per_page'))->toBe(20);
});

it('answers 422, never 500, for wrong-shaped parameters, and never echoes the value', function (string $param, string $shape, int $status) {
    // Given a hostile value for one parameter, with another valid criterion present
    $other = $param === 'q' ? 'topic=tools' : 'q=quokka';
    $value = match ($shape) {
        'array' => $param.'[]=zqmarker',
        'object' => $param.'[a]=zqmarker',
        'nested' => $param.'[a][b]=zqmarker',
        'empty' => $param.'=',
    };

    // When it is sent
    $response = searchApi($other.'&'.$value);

    // Then the status is the expected one, and the marker is never echoed
    expect($response->status())->toBe($status);
    expect($response->getContent())->not->toContain('zqmarker');
})->with(function () {
    foreach (['q', 'topic', 'sort', 'per_page', 'cursor'] as $param) {
        foreach (['array', 'object', 'nested', 'empty'] as $shape) {
            // An empty q, topic, sort and cursor mean "absent"; an empty per_page is not an integer.
            yield "{$param} {$shape}" => [$param, $shape, $shape === 'empty' && $param !== 'per_page' ? 200 : 422];
        }
    }
});

it('refuses every tampered cursor with 422, and takes an empty cursor as page one', function () {
    // Given a valid cursor of each sort, from the endpoint itself
    foreach (range(1, 3) as $_) {
        searchLive('Quokka Tool');
    }
    $cursor = fn (string $sort, string $extra = '') => searchApi("q=quokka&sort={$sort}&per_page=1{$extra}")->json('meta.next_cursor');
    $relevance = SearchCursor::decode($cursor('relevance'));
    $votes = SearchCursor::decode($cursor('votes'));
    $newest = SearchCursor::decode($cursor('newest'));
    $ulid = (string) Str::ulid();

    // When tampered cursors are sent for the matching sort
    $bad = [
        'relevance' => [
            'not base64' => '!!!', 'not json' => rtrim(strtr(base64_encode('xx'), '+/', '-_'), '='), 'json null' => 'bnVsbA',
            'missing flag' => searchCursorOf(array_diff_key($relevance, []) + []),
            'wrong keys' => searchCursorOf(['h' => $relevance['h'], 'x' => 1, '_pointsToNextItems' => true]),
            'wrong order' => searchCursorOf(['score' => $relevance['score'], 'h' => $relevance['h'], 'votes_count' => 1, 'id' => $ulid, '_pointsToNextItems' => true]),
            'score float' => searchCursorOf([...$relevance, 'score' => 3.25, '_pointsToNextItems' => true]),
            'score five decimals' => searchCursorOf([...$relevance, 'score' => '3.00000', '_pointsToNextItems' => true]),
            'score negative' => searchCursorOf([...$relevance, 'score' => '-1.0000', '_pointsToNextItems' => true]),
            'votes string' => searchCursorOf([...$relevance, 'votes_count' => '3', '_pointsToNextItems' => true]),
            'votes negative' => searchCursorOf([...$relevance, 'votes_count' => -1, '_pointsToNextItems' => true]),
            'votes huge' => searchCursorOf([...$relevance, 'votes_count' => 1000001, '_pointsToNextItems' => true]),
            'id not a ulid' => searchCursorOf([...$relevance, 'id' => 'abc', '_pointsToNextItems' => true]),
            'hash uppercase' => searchCursorOf([...$relevance, 'h' => strtoupper($relevance['h']), '_pointsToNextItems' => true]),
            'another hash' => searchCursorOf([...$relevance, 'h' => SearchQuery::fromString('other')->hash('relevance', null), '_pointsToNextItems' => true]),
            'cursor of another sort' => $cursor('votes'),
            'cursor of the same search with sort changed' => $cursor('newest'),
        ],
    ];
    $impossible = [
        'votes impossible day' => ['votes', searchCursorOf([...$votes, 'launch_date' => '2026-02-31', '_pointsToNextItems' => true])],
        'votes timestamp as day' => ['votes', searchCursorOf([...$votes, 'launch_date' => '2026-02-01 00:00:00', '_pointsToNextItems' => true])],
        'newest garbage time' => ['newest', searchCursorOf([...$newest, 'launched_at' => 'yesterday', '_pointsToNextItems' => true])],
        'newest impossible date' => ['newest', searchCursorOf([...$newest, 'launched_at' => '2026-02-31 10:00:00+00:00', '_pointsToNextItems' => true])],
    ];

    // Then every one is a 422 on cursor, never a 500
    foreach ($bad['relevance'] as $label => $value) {
        expect(searchApi('q=quokka&per_page=1&cursor='.urlencode((string) $value))->status())->toBe(422, $label);
    }
    foreach ($impossible as $label => [$sort, $value]) {
        expect(searchApi("q=quokka&sort={$sort}&per_page=1&cursor=".urlencode($value))->status())->toBe(422, $label);
    }

    // And a cursor of another q or another topic is 422, while an empty cursor is page one
    expect(searchApi('q=other&per_page=1&cursor='.urlencode($cursor('relevance')))->status())->toBe(422);
    expect(searchApi('q=quokka&topic=tools&per_page=1&cursor='.urlencode($cursor('relevance')))->status())->toBe(422);
    expect(searchApi('q=quokka&per_page=1&cursor=')->assertOk()->json('data'))->toHaveCount(1);
});

it('accepts a valid cursor of the same search written with other case and punctuation', function () {
    // Given three matches and the cursor of page one of "quokka"
    foreach (range(1, 2) as $_) {
        searchLive('Quokka Tool');
    }
    $cursor = searchApi('q=quokka&per_page=1')->json('meta.next_cursor');

    // When page two is read with the same words written differently
    $two = searchApi('q='.urlencode('QUOKKA!!').'&per_page=1&cursor='.urlencode($cursor));

    // Then the hash still matches
    $two->assertOk();
    expect($two->json('data'))->toHaveCount(1);
});
