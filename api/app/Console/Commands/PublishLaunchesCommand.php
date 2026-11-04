<?php

namespace App\Console\Commands;

use App\Actions\Product\PublishLaunches;
use Illuminate\Console\Command;

class PublishLaunchesCommand extends Command
{
    protected $signature = 'launchpad:publish-launches';

    protected $description = 'Make every scheduled product whose launch day (UTC) has begun live';

    public function handle(PublishLaunches $publish): int
    {
        $count = $publish->handle();

        $this->info("Published {$count} product(s).");

        if ($publish->failed > 0) {
            $this->error("{$publish->failed} product(s) failed and stay scheduled for the next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
