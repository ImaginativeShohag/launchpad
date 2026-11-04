<?php

namespace App\Listeners;

use App\Actions\Notification\NotificationDrafts;
use App\Actions\Notification\RecordNotification;
use App\Events\CommentCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the product's maker (and the person a reply answers) about a new comment: one bell row per comment, at most one
 * mail per (recipient, product, kind, UTC hour). The comment text is never read. Queued, no swallowed exceptions, not
 * named `handle`: see NotifyReviewOutcome.
 */
final class NotifyNewComment implements ShouldQueue
{
    public int $tries = 5;

    public int $timeout = 30;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function notify(CommentCreated $event): void
    {
        foreach (NotificationDrafts::comment($event->commentId) as $draft) {
            app(RecordNotification::class)->handle($draft);
        }
    }
}
