<?php

namespace App\Actions\Views;

/** What one run of the view flush did. Counters are merged only after a batch's transaction has committed. */
final class FlushReport
{
    public int $batches = 0;

    public int $buckets = 0;

    public int $referrerRows = 0;

    public int $skippedProducts = 0;

    public int $failed = 0;
}
