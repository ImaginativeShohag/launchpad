<?php

namespace App\Actions\Follow;

use App\Actions\Follow\Concerns\LocksFollowParties;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UnfollowTopic
{
    use LocksFollowParties;

    /**
     * Idempotent. Same locks as FollowTopic (topic row, then actor, both FOR SHARE); a topic that is gone is the 404.
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

            $changed = DB::table('topic_follows')->where('user_id', $actor->getKey())->where('topic_id', $topicId)->delete() === 1;

            return new FollowResult($changed, false, DB::table('topic_follows')->where('topic_id', $topicId)->count());
        });
    }
}
