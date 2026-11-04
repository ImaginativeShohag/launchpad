<?php

namespace App\Listeners;

use App\Actions\Notification\NotificationDrafts;
use App\Actions\Notification\RecordNotification;
use App\Events\ProductWentLive;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the maker the product is live (bell + mail). A product no longer live when the job runs (taken down again)
 * writes nothing. Queued, no swallowed exceptions, not named `handle`: see NotifyReviewOutcome.
 */
final class NotifyProductLive implements ShouldQueue
{
    public int $tries = 5;

    public int $timeout = 30;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function notify(ProductWentLive $event): void
    {
        $draft = NotificationDrafts::productLive($event->productId);

        if ($draft !== null) {
            app(RecordNotification::class)->handle($draft);
        }
    }
}
