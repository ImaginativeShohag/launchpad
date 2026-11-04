<?php

namespace App\Mail;

use App\Enums\EmailType;

/** Approved, rejected or changes requested. Carries no reviewer note: the owner reads it through the product API. */
final class ReviewOutcomeMail extends OptionalMail
{
    public function __construct(string $userId, string $productId, string $name, string $decision)
    {
        [$subject, $heading, $lead] = match ($decision) {
            'approved' => ["Your product \"{$name}\" was approved", 'Your product was approved', "{$name} passed review and can be scheduled for launch."],
            'changes_requested' => ["Your product \"{$name}\" needs changes", 'Your product needs changes', "The reviewer asked for changes to {$name}. Open the product to read the note and resubmit."],
            default => ["Your product \"{$name}\" was not approved", 'Your product was not approved', "{$name} was not approved. Open the product to read the reviewer's note."],
        };

        parent::__construct($userId, EmailType::ReviewOutcome, $subject, 'review-outcome', [
            'heading' => $heading, 'lead' => $lead, 'name' => $name,
            'url' => MailFor::webUrl('/me/products/'.$productId),
        ]);
    }
}
