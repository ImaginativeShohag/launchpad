<?php

namespace App\Actions\Follow;

use App\Actions\Follow\Concerns\LocksFollowParties;
use App\Exceptions\CommunityRuleViolation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class FollowMember
{
    use LocksFollowParties;

    /**
     * Idempotent: a repeat writes nothing and answers the same state. The target and the actor are re-read under
     * FOR SHARE (one statement, id order), so a follow racing a DeleteAccount either finishes before it (and the
     * deletion then removes the row) or waits and sees the deleted account.
     *
     * @throws CommunityRuleViolation `self_follow` (before any lock) or `member_unavailable` for a suspended target
     * @throws ModelNotFoundException a missing or deleted target
     * @throws AuthorizationException when the actor was suspended or deleted since the request was authorised
     */
    public function handle(User $actor, User $target): FollowResult
    {
        if ($actor->is($target)) {
            throw CommunityRuleViolation::selfFollow();
        }

        return DB::transaction(function () use ($actor, $target): FollowResult {
            $states = $this->lockMembers($actor, $target);

            if ($states['target']?->suspended_at !== null) {
                throw CommunityRuleViolation::memberUnavailable();
            }

            // created_at comes from the column default.
            $changed = DB::table('user_follows')->insertOrIgnore([
                'follower_id' => $actor->getKey(),
                'followed_id' => $target->getKey(),
            ]) === 1;

            return new FollowResult($changed, true, FollowCounts::followersOf($target));
        });
    }
}
