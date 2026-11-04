<?php

namespace App\Console\Commands;

use App\Actions\Export\SweepExports;
use Illuminate\Console\Command;

/**
 * The safety net for GenerateExport: dispatches an export that stayed queued again and fails one whose worker died. Exits
 * non-zero when any row could not be handled, so a monitor sees it.
 */
class SweepExportsCommand extends Command
{
    protected $signature = 'launchpad:sweep-exports';

    protected $description = 'Re-dispatch exports that stayed queued and fail exports whose worker died';

    public function handle(SweepExports $sweep): int
    {
        $result = $sweep->handle();

        $this->info("re-dispatched {$result['redispatched']}, failed stalled {$result['failed_stalled']}, failed {$result['failed']}");

        if ($result['failed'] > 0) {
            $this->error("{$result['failed']} export(s) could not be handled (see the log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
