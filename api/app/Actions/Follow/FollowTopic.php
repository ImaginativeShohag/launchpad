<?php

namespace App\Actions\Follow;

use App\Actions\Follow\Concerns\LocksFollowParties;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class FollowTopic
{
    use LocksFollowParties;

    /**
     * Locks the topic row FOR SHARE first (DeleteTopic locks it FOR UPDATE, so a topic deleted meanwhile is a 404, never
     * a 23503), then the actor FOR SHARE. The slug is resolved to an id by a plain read.
     *
     * @throws ModelNotFoundException an unknown, malformed or just deleted topic
     * @throws AuthorizationException when the actor was suspended or deleted since the request was authorised
     */
    public function handle(User $actor, string $slug): FollowResult
    {
        $topicId = $this->resolveTopicId($slug);

        return DB::transaction(function () use ($actor, $topicId): FollowResult {
            $this->lockTopic($topicId);
            $this->lockMembers($actor);

            $changed = DB::table('topic_follows')->insertOrIgnore(['user_id' => $actor->getKey(), 'topic_id' => $topicId]) === 1;

            return new FollowResult($changed, true, DB::table('topic_follows')->where('topic_id', $topicId)->count());
        });
    }
}
