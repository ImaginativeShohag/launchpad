<?php

namespace App\Mail;

use App\Enums\EmailType;

/** The day before the launch date. Links to the owner's page: a scheduled product is not public yet. */
final class LaunchReminderMail extends OptionalMail
{
    public function __construct(string $userId, string $productId, string $name, string $launchDate)
    {
        parent::__construct($userId, EmailType::LaunchReminder, "{$name} launches tomorrow", 'launch-reminder', [
            'heading' => "{$name} launches tomorrow", 'name' => $name, 'launchDate' => $launchDate,
            'url' => MailFor::webUrl('/me/products/'.$productId),
        ]);
    }
}
