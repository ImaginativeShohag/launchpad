<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A comment or reply was written. Ids only (listeners re-read), dispatched after the surrounding transaction commits.
 * No listener yet: the founder notification is sub-project 5.
 */
final class CommentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $commentId,
        public readonly string $productId,
        public readonly string $userId,
    ) {}
}
