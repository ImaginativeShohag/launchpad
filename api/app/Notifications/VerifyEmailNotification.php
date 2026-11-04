<?php

namespace App\Notifications;

use App\Notifications\Concerns\RetriesDelivery;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\URL;

/**
 * The link opens the React site; the site forwards the same query string to
 * POST /api/v1/auth/email/verify, where the relative signature is checked.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct()
    {
        $this->afterCommit();
    }

    protected function verificationUrl($notifiable): string
    {
        $signed = URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addMinutes((int) config('launchpad.verification_ttl_minutes')),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            absolute: false,
        );

        return config('launchpad.web_url').'/verify-email?'.parse_url($signed, PHP_URL_QUERY);
    }
}
