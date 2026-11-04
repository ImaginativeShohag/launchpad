<?php

namespace App\Notifications\Concerns;

/**
 * Queued notifications default to a single try. Mail must survive a provider outage of a few hours, so retry eight
 * times with growing delays (1, 2, 5, 15, 30 and 60 minutes, then 60 minutes again): about 2.9 hours in all.
 */
trait RetriesDelivery
{
    public int $tries = 8;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 300, 900, 1800, 3600, 3600];
    }
}
