<?php

namespace App\Mail;

use App\Enums\EmailType;

final class DailyTop5Mail extends OptionalMail
{
    /** @param  list<array{name: string, url: string}>  $products */
    public function __construct(string $userId, string $date, array $products)
    {
        parent::__construct($userId, EmailType::DailyTop5, "Yesterday's top 5", 'digest', [
            'heading' => "Yesterday's top 5", 'lead' => "The best launches of {$date} (UTC):", 'products' => $products,
        ]);
    }
}
