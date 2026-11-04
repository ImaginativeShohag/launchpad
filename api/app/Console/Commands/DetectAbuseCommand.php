<?php

namespace App\Console\Commands;

use App\Actions\AntiCheat\DetectAbuse;
use Illuminate\Console\Command;

/**
 * Runs the abuse detection (fake accounts, shared devices, scripted voting) and writes one deduplicated risk signal per
 * finding. Scheduled every five minutes: staff must be able to act on a launch day before 00:00 UTC freezes it, and a
 * missed run is healed by the next because the scan is stateless.
 *
 * It needs no listener on the vote path. Nothing a run does changes a count or a ranking, so seconds of
 * latency buy nothing and the hottest write path stays untouched.
 *
 * After the rules it rescores the members the run touched (and everyone with a score above 0, so decay lowers scores) and
 * flags or unflags the votes of members above `anticheat.flag_risk_score`. Flagged votes still count: no counter moves.
 *
 * Exit code is non-zero when any chunk failed, so a monitor sees it; the other chunks' signals are saved either way.
 */
class DetectAbuseCommand extends Command
{
    protected $signature = 'launchpad:detect-abuse {--dry-run : Run every rule and report the counts without saving anything}';

    protected $description = 'Find fake accounts, shared devices and scripted voting on recent launch days, record risk signals, rescore the members and flag or unflag their votes';

    public function handle(DetectAbuse $detect): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $detect->handle(dryRun: $dryRun);

        if ($dryRun) {
            $this->line('Dry run: nothing was saved.');
        }

        $signals = $result->signals;
        ksort($signals);
        foreach ($signals as $kind => $count) {
            $this->line("{$kind}: {$count}");
        }

        if ($signals === []) {
            $this->line('No new signals.');
        }

        $this->line("users rescored: {$result->usersRescored}");
        $this->line("votes flagged: {$result->flagged}, unflagged: {$result->unflagged}");

        if ($result->failedChunks > 0) {
            $this->error("Failed chunks: {$result->failedChunks}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
