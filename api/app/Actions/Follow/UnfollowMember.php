<?php

namespace App\Actions\Follow;

use App\Exceptions\CommunityRuleViolation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UnfollowMember
{
    /**
     * Idempotent, and it works for a suspended target (a follow can always be taken back). Only the actor is
     * re-read, FOR SHARE; the target row is not locked (a deleted target never reaches here: the binding is a 404).
     *
     * @throws CommunityRuleViolation `self_follow` (symmetric with the follow)
     * @throws AuthorizationException when the actor was suspended or deleted since the request was authorised
     */
    public function handle(User $actor, User $target): FollowResult
    {
        if ($actor->is($target)) {
            throw CommunityRuleViolation::selfFollow();
        }

        return DB::transaction(function () use ($actor, $target): FollowResult {
            $state = DB::table('users')->where('id', $actor->getKey())->sharedLock()->first(['deleted_at', 'suspended_at']);

            if ($state === null || $state->deleted_at !== null || $state->suspended_at !== null) {
                throw new AuthorizationException;
            }

            $changed = DB::table('user_follows')->where('follower_id', $actor->getKey())->where('followed_id', $target->getKey())->delete() === 1;

            return new FollowResult($changed, false, FollowCounts::followersOf($target));
        });
    }
}
