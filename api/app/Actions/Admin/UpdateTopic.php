<?php

namespace App\Actions\Admin;

use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTopic
{
    /** @param  array<string, mixed>  $data  validated input; only the keys present are changed */
    public function handle(User $actor, Topic $topic, array $data): Topic
    {
        try {
            return DB::transaction(function () use ($actor, $topic, $data): Topic {
                $locked = Topic::query()->whereKey($topic->getKey())->lockForUpdate()->firstOrFail();
                $locked->fill(Arr::only($data, ['slug', 'name', 'emoji', 'description', 'position']));
                $fields = array_keys($locked->getDirty());

                if ($fields !== []) {
                    $locked->save();
                    Audit::record('topic.updated', $actor, $locked, ['topic_id' => $locked->getKey(), 'fields' => $fields]);
                }

                $topic->setRawAttributes($locked->getAttributes(), true);

                return $topic;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (isset($data['slug']) && is_string($data['slug'])
                && Topic::query()->where('slug', $data['slug'])->whereKeyNot($topic->getKey())->exists()) {
                throw ValidationException::withMessages(['slug' => ['This slug is already taken.']]);
            }

            throw $e;
        }
    }
}
