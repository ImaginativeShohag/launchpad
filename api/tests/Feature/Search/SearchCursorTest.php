<?php

use App\Actions\Search\SearchCursor;
use App\Actions\Search\SearchQuery;
use App\Enums\SearchSort;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;

/** Pages through a search with the given page size until the cursor ends (never more than 30 pages). @return list<string> ids in order */
function searchPageThrough(?string $q, ?string $topic, ?SearchSort $sort, int $perPage): array
{
    $ids = [];
    $cursor = null;

    for ($page = 0; $page < 30; $page++) {
        $result = searchRun($q, $topic, $sort, $perPage, $cursor);
        $ids = [...$ids, ...$result->products->pluck('id')->all()];
        $cursor = $result->nextCursor;

        if ($cursor === null) {
            return $ids;
        }
    }

    throw new RuntimeException('The cursor never ended.');
}

/** Seven matching products with ties on votes, launch day, launch second and score. @return list<App\Models\Product> */
function searchSevenWithTies(?Topic $topic = null): array
{
    $products = [];

    foreach ([[5, '2026-03-02'], [5, '2026-03-02'], [3, '2026-03-02'], [3, '2026-03-01'], [3, '2026-03-01'], [1, '2026-03-01'], [1, '2026-03-03']] as [$votes, $day]) {
        $products[] = $product = searchLive('Quokka Tool', null, null, ['votes_count' => $votes, 'launch_date' => $day, 'launched_at' => '2026-03-01 10:00:00']);

        if ($topic !== null) {
            searchTopicLink($product, $topic);
        }
    }

    return $products;
}

it('pages every sort to exhaustion with no duplicate and no gap, equal to the unpaged order', function (?string $q, bool $withTopic, ?SearchSort $sort) {
    // Given seven matches with ties on votes, launch day, launch second and score
    $topic = Topic::factory()->create(['slug' => 'tools']);
    searchSevenWithTies($topic);
    $topicSlug = $withTopic ? 'tools' : null;

    // When paged two at a time, and read in one go
    $paged = searchPageThrough($q, $topicSlug, $sort, 2);
    $whole = searchRun($q, $topicSlug, $sort, 50)->products->pluck('id')->all();

    // Then the pages add up to the same seven rows in the same order
    expect($whole)->toHaveCount(7)->and($paged)->toBe($whole)->and(array_unique($paged))->toHaveCount(7);
})->with([
    'relevance' => ['quokka', false, null],
    'relevance with topic' => ['quokka', true, null],
    'votes with query' => ['quokka', false, SearchSort::Votes],
    'votes topic only' => [null, true, null],
    'newest' => ['quokka', false, SearchSort::Newest],
    'newest topic only' => [null, true, SearchSort::Newest],
]);

it('ends with no cursor when exactly a page is left, and on an empty last page', function () {
    // Given four matches
    foreach (range(1, 4) as $_) {
        searchLive('Quokka Tool');
    }

    // When a page of four, a page of three and a search with no match run
    $exact = searchRun('quokka', null, null, 4);
    $short = searchRun('quokka', null, null, 3);
    $none = searchRun('zzxqvbn', null, null, 3);

    // Then only the short page has a cursor
    expect($exact->products)->toHaveCount(4)->and($exact->nextCursor)->toBeNull()
        ->and($short->nextCursor)->not->toBeNull()
        ->and($none->products)->toHaveCount(0)->and($none->nextCursor)->toBeNull();
});

it('round-trips a cursor and treats garbage, another search or another sort as page one', function () {
    // Given five matches and a cursor from page one
    foreach (range(1, 5) as $_) {
        searchLive('Quokka Tool');
    }
    $first = searchRun('quokka', null, null, 2);
    $hash = SearchQuery::fromString('quokka')->hash('relevance', null);
    $decoded = SearchCursor::decode((string) $first->nextCursor);

    // When it is decoded, re-encoded, and garbage and foreign cursors are handed to the action
    $again = SearchCursor::encode(SearchSort::Relevance, $hash, array_diff_key($decoded, ['h' => 1]));
    $garbage = searchRun('quokka', null, null, 2, '%%%not-a-cursor');
    $foreignSearch = searchRun('tool', null, null, 2, $first->nextCursor);
    $foreignSort = searchRun('quokka', null, SearchSort::Votes, 2, $first->nextCursor);

    // Then the round trip is exact, decode of garbage is null, and every bad cursor means page one
    expect($again)->toBe($first->nextCursor)->and($decoded['h'])->toBe($hash);
    expect(SearchCursor::decode('!!'))->toBeNull()->and(SearchCursor::decode('bnVsbA'))->toBeNull()->and(SearchCursor::decode(searchCursorOf(['a' => 1])))->toBeNull();
    expect($garbage->products->pluck('id')->all())->toBe($first->products->pluck('id')->all());
    expect($foreignSearch->products)->toHaveCount(2)->and($foreignSort->products)->toHaveCount(2);
});

it('writes the keyset as an OR chain, never a row comparison', function (SearchSort $sort) {
    // Given a cursor page of each sort
    searchSevenWithTies();
    $first = searchRun('quokka', null, $sort, 2);

    // When the next page runs
    $sql = searchCaptureSql(fn () => searchRun('quokka', null, $sort, 2, $first->nextCursor));
    $products = implode("\n", array_filter($sql, fn (string $s) => str_contains($s, 'from "products"')));

    // Then the condition is an OR chain and has no (a, b) < (x, y) comparison
    expect($products)->toContain(' or ')->not->toMatch('/\([^()]*,[^()]*\)\s*[<>]=?\s*\(/');
})->with([[SearchSort::Relevance], [SearchSort::Votes], [SearchSort::Newest]]);

it('keeps a live product with no launch time out of the newest sort and out of its cursor', function () {
    // Given two products with a launch time and a live one without
    searchLive('Quokka One', null, null, ['launched_at' => '2026-03-01 10:00:00']);
    searchLive('Quokka Two', null, null, ['launched_at' => '2026-03-02 10:00:00']);
    searchLive('Quokka Null', null, null, ['launched_at' => null]);

    // When newest is read whole and paged
    $whole = searchNames('quokka', null, SearchSort::Newest);
    $paged = searchPageThrough('quokka', null, SearchSort::Newest, 1);

    // Then the product without a launch time is not in it, and paging works
    expect($whole)->toBe(['Quokka Two', 'Quokka One'])->and($paged)->toHaveCount(2);
});

it('stores launched_at in whole seconds, which is the precision the newest cursor writes', function () {
    // Given the real column and two products launched in the same second
    $precision = DB::selectOne("select datetime_precision from information_schema.columns where table_name = 'products' and column_name = 'launched_at'")->datetime_precision;
    foreach (range(1, 3) as $_) {
        searchLive('Quokka Tool', null, null, ['launched_at' => '2026-03-01 10:00:00']);
    }

    // When newest is paged one at a time
    $paged = searchPageThrough('quokka', null, SearchSort::Newest, 1);

    // Then the precision is 0 (the cursor's Y-m-d H:i:sP loses nothing) and ties within the second page with no gap
    expect($precision)->toBe(0)->and($paged)->toHaveCount(3)->and(array_unique($paged))->toHaveCount(3);
});
