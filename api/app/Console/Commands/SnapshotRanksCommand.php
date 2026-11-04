<?php

namespace App\Console\Commands;

use App\Actions\Ranking\SnapshotRanks;
use Illuminate\Console\Command;

class SnapshotRanksCommand extends Command
{
    protected $signature = 'launchpad:snapshot-ranks';

    protected $description = "Record today's ranking at the current ten-minute mark (history for later reports)";

    public function handle(SnapshotRanks $snapshot): int
    {
        $count = $snapshot->handle();

        $this->info("Snapshotted {$count} product(s).");

        if ($snapshot->failed > 0) {
            $this->error('The snapshot failed and nothing was written; the next run retries.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
