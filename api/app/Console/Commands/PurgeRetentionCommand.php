<?php

namespace App\Console\Commands;

use App\Actions\Retention\PurgeRetention;
use Illuminate\Console\Command;

class PurgeRetentionCommand extends Command
{
    protected $signature = 'launchpad:purge-retention';

    protected $description = 'Apply the retention settings: empty old vote hashes, delete old auth events, rank snapshots, notifications, finished outbox mail and digest runs, expired exports and their files, old risk signals, empty the IP hash and user agent of old audit rows';

    public function handle(PurgeRetention $purge): int
    {
        $total = $purge->handle();

        foreach ($purge->results as $table => $result) {
            if ($result['failed']) {
                $this->error("{$table}: FAILED after {$result['purged']} row(s) purged (see the log); the other tables were still processed.");

                continue;
            }

            if ($result['capped']) {
                $this->warn("{$table}: {$result['purged']} row(s) purged; stopped at the per-run cap, the next nightly run continues.");

                continue;
            }

            $this->line("{$table}: {$result['purged']} row(s) purged.");
        }

        $this->info("Total: {$total} row(s) purged.");

        if ($purge->failed > 0) {
            $this->error("{$purge->failed} table(s) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
