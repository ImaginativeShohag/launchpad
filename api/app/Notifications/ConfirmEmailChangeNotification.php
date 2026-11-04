<?php

namespace App\Notifications;

use App\Notifications\Concerns\RetriesDelivery;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Sent to the NEW address (on-demand): proves the member controls it. */
class ConfirmEmailChangeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery;

    // The queue payload carries the new address, so it is encrypted at rest (needs APP_KEY / APP_PREVIOUS_KEYS).

    public function __construct(
        private readonly string $userId,
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
        $signed = URL::temporarySignedRoute(
            'auth.email.change.confirm',
            $this->expires,
            ['id' => $this->userId, 'hash' => sha1($this->newEmail)],
            absolute: false,
        );

        return (new MailMessage)
            ->subject('Confirm your new Launchpad email address')
            ->line('Someone asked to use this address for a Launchpad account. If that was you, confirm it below.')
            ->action('Confirm new email', config('launchpad.web_url').'/confirm-email-change?'.parse_url($signed, PHP_URL_QUERY))
            ->line('This link expires in '.config('launchpad.email_change_ttl_minutes').' minutes. If it was not you, ignore this email.');
    }
}
