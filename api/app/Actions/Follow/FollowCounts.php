<?php

namespace App\Actions\Follow;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Follow counts are plain COUNT(*) on the indexed follow tables: follows are not a hot write path, so nothing is denormalised. */
class FollowCounts
{
    /** @return array{followers_count: int, following_count: int} */
    public function forUser(User $user): array
    {
        return [
            'followers_count' => self::followersOf($user),
            'following_count' => DB::table('user_follows')->where('follower_id', $user->getKey())->count(),
        ];
    }

    public function isFollowing(User $viewer, User $target): bool
    {
        return DB::table('user_follows')->where('follower_id', $viewer->getKey())->where('followed_id', $target->getKey())->exists();
    }

    public static function followersOf(User $user): int
    {
        return DB::table('user_follows')->where('followed_id', $user->getKey())->count();
    }
}
