<?php

namespace App\Listeners;

use App\Actions\Notification\NotificationDrafts;
use App\Actions\Notification\RecordNotification;
use App\Events\ExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the maker the export is ready (bell + optional mail). An export that is no longer ready when the job runs (replaced,
 * expired) writes nothing. Queued, no swallowed exceptions (a failure is retried by the queue), and not named `handle`: see
 * NotifyReviewOutcome. The reconcile sweep does not cover exports: they are short-lived and the bell row is best effort.
 */
final class RecordExportReadyNotification implements ShouldQueue
{
    public int $tries = 5;

    public int $timeout = 30;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function notify(ExportReady $event): void
    {
        $draft = NotificationDrafts::exportReady($event->exportId);

        if ($draft !== null) {
            app(RecordNotification::class)->handle($draft);
        }
    }
}
