<?php

namespace App\Actions\Follow;

/** What a follow action did: whether it wrote anything (an idempotent repeat did not), the resulting state and the target's follower count. */
final readonly class FollowResult
{
    public function __construct(public bool $changed, public bool $following, public int $followersCount) {}
}
