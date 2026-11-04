<?php

namespace App\Console\Commands;

use App\Actions\Notification\Reconcile;
use Illuminate\Console\Command;

class ReconcileNotificationsCommand extends Command
{
    protected $signature = 'launchpad:reconcile-notifications';

    protected $description = 'Re-derive the notifications and mail of the last 48 hours from state (fills gaps left by a lost queue job)';

    public function handle(Reconcile $reconcile): int
    {
        $result = $reconcile->handle();

        $this->info("found {$result['found']}, created {$result['created']}, failed {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
