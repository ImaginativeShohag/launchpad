<?php

use App\Models\Topic;

it('lists topics to anyone, ordered by position then name', function () {
    // Given three topics
    Topic::factory()->create(['name' => 'Zeta', 'slug' => 'zeta', 'position' => 1]);
    Topic::factory()->create(['name' => 'Beta', 'slug' => 'beta', 'position' => 1, 'emoji' => '🛠️']);
    Topic::factory()->create(['name' => 'Omega', 'slug' => 'omega', 'position' => 0]);

    // When an anonymous visitor asks
    $response = $this->getJson('/api/v1/topics');

    // Then the list is ordered and carries exactly the public keys
    $response->assertOk()->assertJsonPath('data.0.slug', 'omega')->assertJsonPath('data.1.slug', 'beta')->assertJsonPath('data.2.slug', 'zeta');
    expect(array_keys($response->json('data.1')))->toBe(['id', 'slug', 'name', 'emoji', 'description', 'position']);
});

it('returns an empty list when there are no topics', function () {
    // Given no topics
    // When listed
    // Then the answer is an empty data array
    $this->getJson('/api/v1/topics')->assertOk()->assertExactJson(['data' => []]);
});

it('keeps the order stable when position and name tie', function () {
    // Given two topics with the same position and name but different ids
    $a = Topic::factory()->create(['name' => 'Same', 'slug' => 'same-a', 'position' => 3]);
    $b = Topic::factory()->create(['name' => 'Same', 'slug' => 'same-b', 'position' => 3]);

    // When the list is read
    $ids = collect($this->getJson('/api/v1/topics')->json('data'))->pluck('id')->all();

    // Then the id breaks the tie
    expect($ids)->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});
