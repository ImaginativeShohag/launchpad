<?php

use App\Actions\Search\SearchCursor;
use App\Enums\SearchSort;

it('ranks a name match above a tagline match above a description match', function () {
    // Given the same word in a description, a tagline and a name (created in the opposite order), with equal votes
    searchLive('Gnu', null, 'works with a quokka inside');
    searchLive('Yak', 'a quokka helper');
    searchLive('Quokka Tool');

    // When the word is searched
    // Then the weights order them
    expect(searchNames('quokka'))->toBe(['Quokka Tool', 'Yak', 'Gnu']);
});

it('ranks an exact name above a prefix match of a longer name', function () {
    // Given a name that is the word and a longer name that starts with it
    searchLive('Launchpad');
    searchLive('Launch');

    // When the word is searched
    // Then the exact name comes first
    expect(searchNames('launch'))->toBe(['Launch', 'Launchpad']);
});

it('breaks score ties by votes, then by id', function () {
    // Given four products with identical text: two tied on votes, one higher, one lower
    $low = searchLive('Tie Maker', null, null, ['votes_count' => 1]);
    $a = searchLive('Tie Maker', null, null, ['votes_count' => 5]);
    $b = searchLive('Tie Maker', null, null, ['votes_count' => 5]);
    $top = searchLive('Tie Maker', null, null, ['votes_count' => 9]);
    $tied = collect([$a, $b])->pluck('id')->sort()->values()->all();

    // When searched
    $ids = searchRun('tie maker')->products->pluck('id')->all();

    // Then votes (descending) decide, and id (ascending) breaks the remaining tie
    expect($ids)->toBe([$top->id, ...$tied, $low->id]);
});

it('orders a typo-only name hit after every full-text hit, even a description-only one', function () {
    // Given a name that a typo finds with high similarity, and a description-only hit with many votes
    searchLive('Launchpad', null, null, ['votes_count' => 0]);
    searchLive('Zebra', null, 'we use lanchpad daily', ['votes_count' => 0]);

    // When the typo is searched
    $page = searchRun('lanchpad');

    // Then the full-text (description) hit is first and the typo-only hit second
    expect($page->products->pluck('name')->all())->toBe(['Zebra', 'Launchpad']);
    expect((float) $page->products[0]->getAttribute('score'))->toBeGreaterThanOrEqual(2.0)
        ->and((float) $page->products[1]->getAttribute('score'))->toBeLessThan(1.0001);
});

it('computes a four-decimal score that is stable between runs and equals the one in the cursor', function () {
    // Given three matching products
    searchLive('Quokka Tool');
    searchLive('Yak', 'a quokka helper');
    searchLive('Gnu', null, 'a quokka inside');

    // When the same query runs twice, and a page of two is cut
    $first = searchRun('quokka');
    $second = searchRun('quokka');
    $cut = searchRun('quokka', null, null, 2);
    $cursor = SearchCursor::decode((string) $cut->nextCursor);

    // Then the order and the scores are identical, each score has four decimals, and the cursor holds the last row's score
    expect($second->products->pluck('id')->all())->toBe($first->products->pluck('id')->all());
    foreach ($first->products as $product) {
        expect((string) $product->getAttribute('score'))->toMatch('/\A\d\.\d{4}\z/');
    }
    expect($cursor['score'])->toBe((string) $first->products[1]->getAttribute('score'));
});

it('tunes a small English, Bengali and accented catalogue to a written-down order', function () {
    // Given a small realistic catalogue
    searchLive('Café Finder', 'Find a quiet cafe near you', 'Maps of small cafes', ['votes_count' => 4]);
    searchLive('Pen Pal', 'Write letters with a pen', 'A pen friend service', ['votes_count' => 7]);
    searchLive('Kolom', 'বাংলা কলম অ্যাপ', 'কলম দিয়ে লেখা শিখুন', ['votes_count' => 2]);
    searchLive('Notes', 'Notebook for every pen lover', null, ['votes_count' => 1]);
    searchLive('Penguin Tracker', 'Track penguins', 'Where penguins live', ['votes_count' => 3]);
    searchLive('Launchpad', 'Ship products', 'Launch your product', ['votes_count' => 10]);

    // When searched
    // Then the golden orders hold (name before tagline before description, then votes)
    expect(searchNames('pen'))->toBe(['Pen Pal', 'Penguin Tracker', 'Notes']);
    expect(searchNames('cafe'))->toBe(['Café Finder']);
    expect(searchNames('কলম'))->toBe(['Kolom']);
    expect(searchNames('launch'))->toBe(['Launchpad']);
});

it('sorts by votes and by newest on request', function () {
    // Given matches with different votes and launch times
    searchLive('Quokka Old', null, null, ['votes_count' => 9, 'launched_at' => '2026-01-01 10:00:00', 'launch_date' => '2026-01-01']);
    searchLive('Quokka New', null, null, ['votes_count' => 1, 'launched_at' => '2026-02-01 10:00:00', 'launch_date' => '2026-02-01']);

    // When sorted
    // Then votes and newest each order as asked
    expect(searchNames('quokka', null, SearchSort::Votes))->toBe(['Quokka Old', 'Quokka New']);
    expect(searchNames('quokka', null, SearchSort::Newest))->toBe(['Quokka New', 'Quokka Old']);
});
