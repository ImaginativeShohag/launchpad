<?php

use App\Models\Topic;
use Database\Seeders\TopicSeeder;

it('seeds the starter topics once and keeps admin edits when run again', function () {
    // Given a seeded database
    $this->seed(TopicSeeder::class);
    $count = Topic::query()->count();
    Topic::query()->where('slug', 'design')->update(['name' => 'Renamed by an admin']);

    // When the seeder runs again
    $this->seed(TopicSeeder::class);

    // Then nothing is duplicated and the edit survives
    expect($count)->toBeGreaterThanOrEqual(10)->and(Topic::query()->count())->toBe($count);
    expect(Topic::query()->where('slug', 'design')->value('name'))->toBe('Renamed by an admin');
});
