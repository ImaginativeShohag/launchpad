<?php

namespace App\Console\Commands;

use App\Actions\Email\QueueLaunchReminders;
use Illuminate\Console\Command;

class QueueLaunchRemindersCommand extends Command
{
    protected $signature = 'launchpad:queue-launch-reminders';

    protected $description = 'Remind the makers of products that launch tomorrow (UTC), once per product and launch date';

    public function handle(QueueLaunchReminders $reminders): int
    {
        $result = $reminders->handle();

        if ($reminders->outsideWindow) {
            $this->line('outside the send window');

            return self::SUCCESS;
        }

        $this->info("found {$result['found']}, created {$result['created']}, failed {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
