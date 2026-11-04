<?php

namespace App\Console\Commands;

use App\Actions\Ranking\RecomputeHotScores;
use Illuminate\Console\Command;

class RecomputeHotScoresCommand extends Command
{
    protected $signature = 'launchpad:recompute-hot-scores';

    protected $description = 'Recompute the hot score of every live product launching today (UTC)';

    public function handle(RecomputeHotScores $recompute): int
    {
        $count = $recompute->handle();

        $this->info("Recomputed {$count} score(s).");

        if ($recompute->failed > 0) {
            $this->error("{$recompute->failed} product(s) failed and keep their old score until the next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
