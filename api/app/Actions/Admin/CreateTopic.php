<?php

namespace App\Actions\Admin;

use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTopic
{
    /** @param  array<string, mixed>  $data  validated input */
    public function handle(User $actor, array $data): Topic
    {
        try {
            return DB::transaction(function () use ($actor, $data): Topic {
                // Stays inside the 0..1000 the API accepts; the first topic is 0 like the seeder's.
                $max = Topic::query()->max('position');
                $position = $data['position'] ?? min(1000, $max === null ? 0 : (int) $max + 1);

                $topic = Topic::query()->create([
                    ...Arr::only($data, ['slug', 'name']),
                    'emoji' => $data['emoji'] ?? null,
                    'description' => $data['description'] ?? null,
                    'position' => $position,
                ]);

                Audit::record('topic.created', $actor, $topic, ['topic_id' => $topic->getKey()]);

                return $topic;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Re-query instead of reading the driver message (L4): only a slug clash is the admin's mistake.
            if (Topic::query()->where('slug', $data['slug'])->exists()) {
                throw ValidationException::withMessages(['slug' => ['This slug is already taken.']]);
            }

            throw $e;
        }
    }
}
