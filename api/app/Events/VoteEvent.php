<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Base of the vote events: ids only (listeners re-read counts), dispatched after the surrounding transaction commits. */
abstract class VoteEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $productId, public readonly string $userId) {}
}
