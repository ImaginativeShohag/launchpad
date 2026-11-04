<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An export finished and can be downloaded. Raised once per export, after the finishing transaction committed. Ids only (a
 * listener re-reads): no file name, URL or number travels with it.
 */
final class ExportReady implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $exportId,
        public readonly string $productId,
        public readonly string $userId,
    ) {}
}
