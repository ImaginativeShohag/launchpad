<?php

namespace App\Notifications;

use App\Notifications\Concerns\RetriesDelivery;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    protected function resetUrl($notifiable): string
    {
        return config('launchpad.web_url').'/reset-password?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());
    }
}
