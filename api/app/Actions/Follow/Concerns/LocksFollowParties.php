<?php

namespace App\Actions\Follow\Concerns;

use App\Models\Topic;
use App\Models\User;
use App\Rules\SlugFormat;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The shared user-row locks of the follow actions. A follow is a leaf write: it takes user rows FOR SHARE (shared
 * locks cannot deadlock with each other, and they wait for a DeleteAccount that holds the row FOR UPDATE) and never a
 * product row, so the global lock order is not touched. Rows are always taken in id order, in one statement.
 */
trait LocksFollowParties
{
    /**
     * Locks the actor and (optionally) a target member FOR SHARE in id order and returns their locked states.
     *
     * @return array{actor: \stdClass, target: \stdClass|null}
     *
     * @throws ModelNotFoundException when the target is missing or a deleted account (the uniform 404)
     * @throws AuthorizationException when the actor is suspended or deleted
     */
    private function lockMembers(User $actor, ?User $target = null): array
    {
        $ids = array_values(array_unique(array_filter([(string) $actor->getKey(), $target === null ? null : (string) $target->getKey()])));
        sort($ids, SORT_STRING);

        $states = DB::table('users')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'deleted_at', 'suspended_at'])->keyBy('id');

        $targetState = $target === null ? null : ($states[(string) $target->getKey()] ?? null);

        if ($target !== null && ($targetState === null || $targetState->deleted_at !== null)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        $own = $states[(string) $actor->getKey()] ?? null;

        if ($own === null || $own->deleted_at !== null || $own->suspended_at !== null) {
            throw new AuthorizationException;
        }

        return ['actor' => $own, 'target' => $targetState];
    }

    /**
     * The topic id for a slug, by a plain read.
     *
     * @throws ModelNotFoundException an unknown or malformed slug
     */
    private function resolveTopicId(string $slug): string
    {
        // A value that cannot be a slug never reaches the database (a NUL byte is a Postgres error).
        if (! SlugFormat::matches($slug, 1, 40)) {
            throw (new ModelNotFoundException)->setModel(Topic::class);
        }

        $id = DB::table('topics')->where('slug', $slug)->value('id');

        if (! is_string($id)) {
            throw (new ModelNotFoundException)->setModel(Topic::class);
        }

        return $id;
    }

    /**
     * FOR SHARE on the topic row, before any user row (DeleteTopic holds it FOR UPDATE).
     *
     * @throws ModelNotFoundException when the topic was deleted since it was resolved
     */
    private function lockTopic(string $topicId): void
    {
        if (! DB::table('topics')->where('id', $topicId)->sharedLock()->exists()) {
            throw (new ModelNotFoundException)->setModel(Topic::class);
        }
    }
}
