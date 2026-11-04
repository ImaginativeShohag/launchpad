<?php

namespace App\Console\Commands;

use App\Actions\Ranking\FreezeDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class FreezeDayCommand extends Command
{
    protected $signature = 'launchpad:freeze-day {--from= : Backfill: look back to this launch day (YYYY-MM-DD) instead of the default 31-day window}';

    protected $description = 'Write the permanent ranking of every finished launch day that has none yet (late arrivals are appended)';

    public function handle(FreezeDay $freeze): int
    {
        $from = $this->option('from');

        if ($from !== null) {
            $valid = is_string($from)
                && Validator::make(['from' => $from], ['from' => ['regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d']])->passes()
                && checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4));

            if (! $valid) {
                $this->error('--from must be a real date in the form YYYY-MM-DD.');

                return self::INVALID;
            }
        }

        $count = $freeze->handle(null, is_string($from) ? $from : null);

        $this->info("Froze {$count} day(s).");

        if ($freeze->failed > 0) {
            $this->error("{$freeze->failed} day(s) failed and stay unfrozen for the next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
