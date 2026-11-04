<?php

namespace App\Mail;

use App\Enums\EmailType;

final class WeeklyBestMail extends OptionalMail
{
    /** @param  list<array{name: string, url: string}>  $products */
    public function __construct(string $userId, string $weekStart, array $products)
    {
        parent::__construct($userId, EmailType::WeeklyBest, "This week's best on Launchpad", 'digest', [
            'heading' => "This week's best on Launchpad", 'lead' => "The best launches of the week starting {$weekStart} (UTC):", 'products' => $products,
        ]);
    }
}
