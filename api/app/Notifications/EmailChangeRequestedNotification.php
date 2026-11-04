<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RetriesDelivery;
use App\Support\MaskEmail;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/** Sent to the OLD address: a heads-up with a one-click way to cancel. Names the new address masked only. */
class EmailChangeRequestedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery;

    /** The member may be deleted before the job runs; then the mail is dropped, not retried. */
    public bool $deleteWhenMissingModels = true;

    // The queue payload carries the new address, so it is encrypted at rest (needs APP_KEY / APP_PREVIOUS_KEYS).

    public function __construct(
        private readonly string $newEmail,
        private readonly CarbonInterface $expires,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof User) {
            throw new InvalidArgumentException('The email-change notice is sent to a member.');
        }

        $signed = URL::temporarySignedRoute(
            'auth.email.change.cancel',
            $this->expires,
            ['id' => $notifiable->getKey(), 'hash' => sha1($this->newEmail)],
            absolute: false,
        );

        return (new MailMessage)
            ->subject('Your Launchpad email address is being changed')
            ->line('A request was made to change your account email to '.MaskEmail::mask($this->newEmail).'. Nothing changes until that address is confirmed.')
            ->line('If this was not you, cancel it now.')
            ->action('Cancel the change', config('launchpad.web_url').'/cancel-email-change?'.parse_url($signed, PHP_URL_QUERY));
    }
}
