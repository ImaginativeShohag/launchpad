<?php

namespace Database\Factories;

use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Topic> */
class TopicFactory extends Factory
{
    protected $model = Topic::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        /** @var string $words */
        $words = fake()->unique()->words(2, true);
        $name = Str::title($words);

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'emoji' => null,
            'description' => null,
            'position' => 0,
        ];
    }
}
