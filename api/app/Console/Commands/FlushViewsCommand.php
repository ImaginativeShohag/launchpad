<?php

namespace App\Console\Commands;

use App\Actions\Views\FlushViews;
use Illuminate\Console\Command;
use Throwable;

class FlushViewsCommand extends Command
{
    protected $signature = 'launchpad:flush-views';

    protected $description = 'Write the page views counted in Redis into the hourly and referrer tables (every minute)';

    public function handle(FlushViews $flush): int
    {
        try {
            $report = $flush->handle();
        } catch (Throwable $e) {
            // Redis itself failed before anything moved; nothing was lost, the next run retries.
            report($e);
            $this->error('The view counters could not be read; nothing was flushed.');

            return self::FAILURE;
        }

        $this->info("flushed {$report->batches} batch(es): {$report->buckets} bucket(s), {$report->referrerRows} referrer row(s), {$report->skippedProducts} product(s) skipped.");

        if ($report->failed > 0) {
            $this->error("{$report->failed} batch(es) failed and stay for the next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
