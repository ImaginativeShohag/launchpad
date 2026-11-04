<?php

namespace App\Mail;

use App\Enums\EmailType;

final class ProductLiveMail extends OptionalMail
{
    public function __construct(string $userId, string $slug, string $name)
    {
        parent::__construct($userId, EmailType::ProductLive, "{$name} is live", 'product-live', [
            'heading' => "{$name} is live", 'name' => $name,
            'url' => MailFor::webUrl('/products/'.$slug),
        ]);
    }
}
