<?php

namespace App\Actions\AntiCheat;

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use Carbon\CarbonImmutable;

/** The four columns of a stored signal that its score contribution depends on. */
final readonly class ScoredSignal
{
    public function __construct(
        public RiskSignalKind $kind,
        public int $weight,
        public CarbonImmutable $detectedAt,
        public ?RiskOutcome $outcome,
    ) {}
}
