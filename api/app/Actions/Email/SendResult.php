<?php

namespace App\Actions\Email;

/** What happened to one claimed outbox row. `transientFailure` feeds the run's breaker and exit code. */
final class SendResult
{
    /** @param  'sent'|'retried'|'failed'|'suppressed'|'skipped'  $outcome */
    public function __construct(
        public readonly string $outcome,
        public readonly bool $transientFailure = false,
    ) {}
}
