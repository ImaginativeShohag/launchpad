<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base of every product domain event. Carries ids only (sub-project 5 re-reads what it needs) and is dispatched
 * after the surrounding transaction commits, so a listener never sees rolled-back state.
 */
abstract class ProductEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $productId, public readonly ?string $actorId = null) {}
}
