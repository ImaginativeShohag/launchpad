<?php

use App\Models\Product;

beforeEach(function () {
    $this->travelTo(dashNow());
    // A product launched long enough ago for the window tests; the maker is signed in.
    $this->product = dashMakerProduct('2025-01-01');
});

it('rejects a bad granularity with one 422 message', function (string $query) {
    // Given a maker
    // When a series is read with the bad granularity (an empty value becomes null before validation)
    $response = $this->getJson("/api/v1/me/products/{$this->product->id}/dashboard/series?{$query}");

    // Then it is a validation error on granularity alone
    $response->assertStatus(422)->assertJsonValidationErrors(['granularity']);
    expect($response->json('errors.granularity'))->toHaveCount(1)->and(array_keys($response->json('errors')))->toBe(['granularity']);
})->with([
    'empty' => 'granularity=',
    'array' => 'granularity[]=hour',
    'object' => 'granularity[a]=b',
    'unknown' => 'granularity=hot',
    'upper case' => 'granularity=HOUR',
    'number' => 'granularity=1',
    'week' => 'granularity=week',
]);

it('rejects a bad date on from or to for the series and the referrers', function (string $endpoint, string $field, string $value) {
    // Given a maker
    // When the date is sent (arrays and objects as the query string spells them)
    $response = $this->getJson("/api/v1/me/products/{$this->product->id}/dashboard/{$endpoint}?{$field}={$value}");

    // Then it is a single validation error on that field, never a 500
    $response->assertStatus(422);
    expect(array_keys($response->json('errors')))->toBe([$field])->and($response->json("errors.{$field}"))->toHaveCount(1);
})->with(['series', 'referrers'])->with(['from', 'to'])->with([
    'empty' => '',
    'impossible day' => '2026-02-30',
    'month 13' => '2026-13-01',
    'two digit year' => '26-10-01',
    'one digit day' => '2026-10-1',
    'iso timestamp' => '2026-10-01T00:00:00Z',
    'garbage' => 'garbage',
    'year 0000' => '0000-01-01',
    'year 9999' => '9999-12-31',
    'array' => '[]=2026-10-01',
    'object' => '[a]=2026-10-01',
]);

it('accepts a date with surrounding whitespace once trimmed', function () {
    // Given a maker
    // When a date has a leading space (TrimStrings makes it a valid date)
    $response = $this->getJson("/api/v1/me/products/{$this->product->id}/dashboard/referrers?from=%202026-10-01&to=2026-10-21");

    // Then it is accepted
    $response->assertOk()->assertJsonPath('data.from', '2026-10-01');
});

it('refuses from after to', function (string $endpoint) {
    // Given a maker
    // When from is after to
    $response = dashApi('me', $this->product->id, $endpoint, ['from' => '2026-10-10', 'to' => '2026-10-09']);

    // Then it is a 422 on from
    $response->assertStatus(422)->assertJsonValidationErrors(['from']);
})->with(['series', 'referrers']);

it('caps an hour window at 7 days and a day window at 366', function () {
    // Given a maker with an old product
    // When the caps are tried
    $hour7 = dashApi('me', $this->product->id, 'series', ['granularity' => 'hour', 'from' => '2026-10-15', 'to' => '2026-10-21']);
    $hour8 = dashApi('me', $this->product->id, 'series', ['granularity' => 'hour', 'from' => '2026-10-14', 'to' => '2026-10-21']);
    $day366 = dashApi('me', $this->product->id, 'series', ['granularity' => 'day', 'from' => '2025-10-21', 'to' => '2026-10-21']);
    $day367 = dashApi('me', $this->product->id, 'series', ['granularity' => 'day', 'from' => '2025-10-20', 'to' => '2026-10-21']);
    $ref366 = dashApi('me', $this->product->id, 'referrers', ['from' => '2025-10-21', 'to' => '2026-10-21']);
    $ref367 = dashApi('me', $this->product->id, 'referrers', ['from' => '2025-10-20', 'to' => '2026-10-21']);

    // Then exactly the cap is fine and one more is a 422, with the right point counts
    $hour7->assertOk()->assertJsonCount(168, 'data.points');
    $hour8->assertStatus(422)->assertJsonValidationErrors(['to']);
    $day366->assertOk()->assertJsonCount(366, 'data.points');
    $day367->assertStatus(422)->assertJsonValidationErrors(['to']);
    $ref366->assertOk();
    $ref367->assertStatus(422)->assertJsonValidationErrors(['to']);
});

it('ignores unknown query keys and a JSON body on a GET', function () {
    // Given a maker
    // When extra keys and a body arrive
    $series = $this->getJson("/api/v1/me/products/{$this->product->id}/dashboard/series?foo=bar&granularity=day&from=2026-10-20", ['granularity' => ['x']]);
    $json = $this->json('GET', "/api/v1/me/products/{$this->product->id}/dashboard/referrers", ['from' => 'garbage', 'to' => ['1']]);
    $summary = $this->json('GET', "/api/v1/me/products/{$this->product->id}/dashboard?from=garbage", ['granularity' => 5]);
    $rank = $this->getJson("/api/v1/me/products/{$this->product->id}/dashboard/rank?granularity[]=1&from=garbage");

    // Then they are all answered normally
    $series->assertOk()->assertJsonPath('data.granularity', 'day');
    $json->assertOk();
    $summary->assertOk();
    $rank->assertOk();
});

it('accepts valid parameters on a product before its launch and returns empty data', function () {
    // Given a draft of the maker
    $draft = Product::factory()->create(['maker_id' => $this->product->maker_id]);

    // When parameters are sent
    $series = dashApi('me', $draft->id, 'series', ['granularity' => 'day', 'from' => '2026-10-01', 'to' => '2026-10-05'])->assertOk();
    $referrers = dashApi('me', $draft->id, 'referrers', ['from' => '2026-10-01', 'to' => '2026-10-05'])->assertOk();

    // Then both are empty
    expect($series->json('data'))->toBe(['granularity' => 'day', 'from' => null, 'to' => null, 'points' => []])
        ->and($referrers->json('data'))->toBe(['from' => null, 'to' => null, 'total' => 0, 'sources' => [], 'by_category' => []]);
});

it('clamps the window and echoes the clamped dates', function () {
    // Given a product launched two days ago
    $recent = Product::factory()->live('2026-10-19')->create(['maker_id' => $this->product->maker_id]);

    // When a window reaches before the launch and after today
    $series = dashApi('me', $recent->id, 'series', ['granularity' => 'day', 'from' => '2026-10-01', 'to' => '2026-10-25'])->assertOk();

    // Then it is clamped to the launch and today
    expect($series->json('data.from'))->toBe('2026-10-19')->and($series->json('data.to'))->toBe('2026-10-21');
});

it('answers a stale product id before parameters even for the maker\'s own deleted product', function () {
    // Given a soft-deleted product of the maker
    $gone = Product::factory()->live(DASH_DAY)->create(['maker_id' => $this->product->maker_id, 'deleted_at' => now()]);

    // When read with invalid parameters
    // Then the 404 wins over the 422
    dashApi('me', $gone->id, 'series', ['granularity' => ['x']])->assertNotFound();
});

it('rejects an hour window that is not a calendar day pair without leaking a 500', function () {
    // Given a maker
    // When wildly shaped values arrive together
    $response = $this->getJson("/api/v1/me/products/{$this->product->id}/dashboard/series?granularity[]=1&from[]=1&to[a]=2");

    // Then it is a 422 with every field named, never a 500
    $response->assertStatus(422)->assertJsonValidationErrors(['granularity', 'from', 'to']);
});
