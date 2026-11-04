<?php

namespace App\Listeners;

use App\Actions\Notification\NotificationDrafts;
use App\Actions\Notification\RecordNotification;
use App\Events\ProductApproved;
use App\Events\ProductChangesRequested;
use App\Events\ProductRejected;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the maker how the review went (bell + mail). Queued, and it does NOT swallow exceptions: the job must fail and
 * retry, durability beats quiet (the hourly reconcile sweep is the second net). The event carries ids only; the draft is
 * re-read from them.
 *
 * Deliberately NOT named `handle` (Laravel's listener discovery would register it on top of the explicit Event::listen
 * calls in AppServiceProvider and every review would notify twice). The timeout stays below the queue's `retry_after`.
 */
final class NotifyReviewOutcome implements ShouldQueue
{
    public int $tries = 5;

    public int $timeout = 30;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function notify(ProductApproved|ProductRejected|ProductChangesRequested $event): void
    {
        $draft = NotificationDrafts::reviewOutcome($event->reviewId);

        if ($draft !== null) {
            app(RecordNotification::class)->handle($draft);
        }
    }
}
