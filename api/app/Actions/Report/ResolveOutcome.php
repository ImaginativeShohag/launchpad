<?php

namespace App\Actions\Report;

use App\Enums\ReportAction;

/** What a resolve did: how many open reports it closed and whether the target itself changed. */
final readonly class ResolveOutcome
{
    /** @param  'comment'|'product'  $type */
    public function __construct(
        public int $closed,
        public bool $targetChanged,
        public string $type,
        public string $targetId,
        public ReportAction $action,
    ) {}
}
