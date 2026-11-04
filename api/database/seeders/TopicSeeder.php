<?php

namespace Database\Seeders;

use App\Models\Topic;
use Illuminate\Database\Seeder;

class TopicSeeder extends Seeder
{
    /** Starter list. `firstOrCreate` by slug, so a re-run never overwrites what an admin edited. */
    public function run(): void
    {
        $topics = [
            ['ai', 'Artificial Intelligence', '🤖'],
            ['developer-tools', 'Developer Tools', '🛠️'],
            ['productivity', 'Productivity', '⚡'],
            ['design', 'Design', '🎨'],
            ['fintech', 'Fintech', '💳'],
            ['health', 'Health', '🩺'],
            ['education', 'Education', '🎓'],
            ['marketing', 'Marketing', '📣'],
            ['open-source', 'Open Source', '🌱'],
            ['saas', 'SaaS', '☁️'],
            ['mobile', 'Mobile', '📱'],
            ['games', 'Games', '🎮'],
        ];

        foreach ($topics as $position => [$slug, $name, $emoji]) {
            Topic::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'emoji' => $emoji, 'description' => null, 'position' => $position]);
        }
    }
}
