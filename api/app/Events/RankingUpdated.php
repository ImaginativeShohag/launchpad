<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Today's hot scores changed. Carries the launch day only (listeners re-read the ranking), never product or member data. */
final class RankingUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  string  $day  launch day, Y-m-d (UTC) */
    public function __construct(public readonly string $day) {}
}
