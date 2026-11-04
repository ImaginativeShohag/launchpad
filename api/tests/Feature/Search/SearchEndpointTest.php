<?php

it('searches by query, by query and topic, and by topic alone, with the default sort of each', function () {
    // Given three products in a topic and one outside it, with different votes
    $topicProducts = searchTopicWithProducts('tools', 3);
    searchLive('Quokka Outside');

    // When each kind of search is made
    $query = searchApi('q=quokka');
    $both = searchApi('q=quokka&topic=tools');
    $topicOnly = searchApi('topic=tools');

    // Then the defaults are relevance for a query and votes for a topic alone, and the topic filter narrows
    expect($query->assertOk()->json('meta.sort'))->toBe('relevance')->and($query->json('data'))->toHaveCount(4);
    expect($both->json('data'))->toHaveCount(3)->and($both->json('meta.sort'))->toBe('relevance');
    expect($topicOnly->json('meta.sort'))->toBe('votes')->and($topicOnly->json('data'))->toHaveCount(3)->and($topicOnly->json('meta.query'))->toBeNull();
    expect(collect($both->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect($topicProducts)->pluck('id')->sort()->values()->all());
});

it('honours an explicit sort of newest and of votes with a query', function () {
    // Given two matches with different votes and launch times
    searchLive('Quokka Old', null, null, ['votes_count' => 9, 'launched_at' => '2026-01-01 10:00:00', 'launch_date' => '2026-01-01']);
    searchLive('Quokka New', null, null, ['votes_count' => 1, 'launched_at' => '2026-02-01 10:00:00', 'launch_date' => '2026-02-01']);

    // When sorted both ways
    $newest = searchApi('q=quokka&sort=newest');
    $votes = searchApi('q=quokka&sort=votes');

    // Then each order and the echoed sort follow
    expect($newest->json('data.*.name'))->toBe(['Quokka New', 'Quokka Old'])->and($newest->json('meta.sort'))->toBe('newest');
    expect($votes->json('data.*.name'))->toBe(['Quokka Old', 'Quokka New'])->and($votes->json('meta.sort'))->toBe('votes');
});

it('has exactly the documented body and echoes the normalised tokens', function () {
    // Given a product
    searchLive('Launch Pad');

    // When searched with noisy input and a per_page
    $response = searchApi('q='.urlencode('Launch-Pad!!').'&per_page=5')->assertOk();

    // Then the body has data, links and meta only, and meta.query is the tokens as the server used them
    expect(array_keys($response->json()))->toBe(['data', 'links', 'meta']);
    expect($response->json('links'))->toBe(['next' => null]);
    expect($response->json('meta'))->toBe(['query' => 'launch pad', 'topic' => null, 'sort' => 'relevance', 'per_page' => 5, 'next_cursor' => null]);
    expect($response->json())->not->toHaveKey('total')->and($response->json())->not->toHaveKey('meta.total');
});

it('links to the next page, which follows to page two', function () {
    // Given five matches
    foreach (range(1, 5) as $_) {
        searchLive('Quokka Tool');
    }

    // When page one of two is read and its next link is followed
    $one = searchApi('q=Quokka!&per_page=2')->assertOk();
    $two = $this->getJson($one->json('links.next'))->assertOk();
    $three = $this->getJson($two->json('links.next'))->assertOk();

    // Then the link is a full URL with the normalised query, and the pages cover every product once
    expect($one->json('links.next'))->toStartWith(url('/api/v1/search').'?q=quokka&sort=relevance&per_page=2&cursor=')
        ->and($one->json('meta.next_cursor'))->not->toBeNull();
    $ids = [...$one->json('data.*.id'), ...$two->json('data.*.id'), ...$three->json('data.*.id')];
    expect($ids)->toHaveCount(5)->and(array_unique($ids))->toHaveCount(5)->and($three->json('links.next'))->toBeNull();
});

it('answers 200 with an empty page and no cursor when nothing matches', function () {
    // Given a product
    searchLive('Launchpad');

    // When nothing matches
    $response = searchApi('q=zzxqvbn');

    // Then it is an empty 200
    $response->assertOk();
    expect($response->json('data'))->toBe([])->and($response->json('links.next'))->toBeNull()->and($response->json('meta.next_cursor'))->toBeNull();
});

it('ignores a JSON body on a GET', function () {
    // Given a matching and a non-matching product
    searchLive('Quokka Tool');
    searchLive('Other Thing');

    // When the body says something else
    $response = $this->json('GET', '/api/v1/search?q=quokka', ['q' => 'other', 'topic' => 'nope', 'sort' => 'bad']);

    // Then only the query string counts
    $response->assertOk();
    expect($response->json('data.*.name'))->toBe(['Quokka Tool'])->and($response->json('meta.query'))->toBe('quokka');
});

it('sets only the device cookie for an anonymous caller', function () {
    // Given a product
    searchLive('Quokka Tool');

    // When a guest searches
    $response = searchApi('q=quokka');

    // Then only lp_did is set
    expect(array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()))->toBe(['lp_did']);
});

it('repeats the typed text in the next link when the normalised phrase would be longer than a request may be', function () {
    // Given a ligature that NFKC expands to three letters, and matches for a second page
    $word = str_repeat("\u{FB03}", 11);
    foreach (range(1, 3) as $_) {
        searchLive('Quokka Tool', null, 'about '.str_repeat('ffi', 11));
    }

    // When a 95-character query of eight such words is paged
    $q = implode(' ', array_fill(0, 8, $word));
    $one = searchApi('q='.urlencode($q).'&per_page=2')->assertOk();

    // Then the next link is short enough to follow (it carries the typed text, not the 400-character phrase)
    expect(mb_strlen((string) $one->json('meta.query')))->toBeGreaterThan(100);
    expect($one->json('links.next'))->not->toBeNull();
    $this->getJson($one->json('links.next'))->assertOk();
});
