<?php

namespace App\Console\Commands;

use App\Actions\Email\DigestResult;
use App\Actions\Email\QueueDigest;
use Illuminate\Console\Command;

class QueueDigestsCommand extends Command
{
    protected $signature = 'launchpad:queue-digests';

    protected $description = 'Queue the daily top five and (on Mondays) the weekly best digest for every eligible member, resumably';

    public function handle(QueueDigest $digests): int
    {
        $results = $digests->handle();

        foreach ($results as $kind => $result) {
            $this->line($result === null && in_array($kind, $digests->failedKinds, true) ? "{$kind}: FAILED (see the log)" : $this->describe($kind, $result));
        }

        if ($digests->failed > 0) {
            $this->error("{$digests->failed} digest kind(s) failed (see the log); the other kind was still processed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function describe(string $kind, ?DigestResult $result): string
    {
        if ($result === null) {
            return "{$kind}: not due";
        }

        return match ($result->state) {
            'waiting' => "{$kind} {$result->period}: waiting",
            'already_done' => "{$kind} {$result->period}: already done",
            'abandoned' => "{$kind} {$result->period}: abandoned after its send day, {$result->enqueued} enqueued",
            default => "{$kind} {$result->period}: completed, {$result->enqueued} enqueued",
        };
    }
}
