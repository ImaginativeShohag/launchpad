<?php

namespace App\Actions\Email;

/** What one digest kind did in this run. `period` is the covered day (daily) or the Monday of the covered week (weekly). */
final class DigestResult
{
    /** @param  'waiting'|'completed'|'resumed'|'abandoned'|'already_done'  $state */
    public function __construct(
        public readonly string $kind,
        public readonly string $period,
        public readonly int $enqueued,
        public readonly bool $completed,
        public readonly string $state,
    ) {}
}
