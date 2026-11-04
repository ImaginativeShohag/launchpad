<?php

use App\Enums\EmailType;
use App\Enums\NotificationType;

it('lists the seven in-site notification types', function () {
    // Given the enum
    // When its values are read
    $values = NotificationType::values();

    // Then they are exactly the seven documented strings
    expect($values)->toBe(['review_approved', 'review_rejected', 'review_changes_requested', 'launch_reminder', 'product_live', 'new_comment', 'export_ready']);
});

it('maps every notification type to its email type and message key', function () {
    // Given each type
    // When it is mapped
    // Then the three review outcomes share one email type, the others map one to one
    expect(NotificationType::ReviewApproved->emailType())->toBe(EmailType::ReviewOutcome)
        ->and(NotificationType::ReviewRejected->emailType())->toBe(EmailType::ReviewOutcome)
        ->and(NotificationType::ReviewChangesRequested->emailType())->toBe(EmailType::ReviewOutcome)
        ->and(NotificationType::LaunchReminder->emailType())->toBe(EmailType::LaunchReminder)
        ->and(NotificationType::ProductLive->emailType())->toBe(EmailType::ProductLive)
        ->and(NotificationType::NewComment->emailType())->toBe(EmailType::NewComment)
        ->and(NotificationType::ExportReady->emailType())->toBe(EmailType::ExportReady);
    foreach (NotificationType::cases() as $type) {
        expect($type->messageKey())->toBe('notifications.'.$type->value);
    }
});

it('lists the ten email types', function () {
    // Given the enum
    // When its values are read
    // Then they are the documented ten
    expect(EmailType::values())->toBe(['verify_email', 'reset_password', 'email_change', 'review_outcome', 'launch_reminder', 'product_live', 'new_comment', 'daily_top5', 'weekly_best', 'export_ready']);
});

it('marks exactly the seven preference types as optional, in a fixed order', function () {
    // Given every email type
    // When the optional ones are collected
    $optional = array_values(array_filter(EmailType::cases(), fn (EmailType $t) => $t->isOptional()));

    // Then they are the seven preference types (the export mail last), the auth mail is not
    expect(array_map(fn (EmailType $t) => $t->value, $optional))->toEqualCanonicalizing(['review_outcome', 'launch_reminder', 'product_live', 'new_comment', 'daily_top5', 'weekly_best', 'export_ready']);
    expect(array_map(fn (EmailType $t) => $t->value, EmailType::preferenceTypes()))->toBe(['review_outcome', 'launch_reminder', 'product_live', 'new_comment', 'daily_top5', 'weekly_best', 'export_ready']);
    expect(EmailType::VerifyEmail->isOptional())->toBeFalse()
        ->and(EmailType::ResetPassword->isOptional())->toBeFalse()
        ->and(EmailType::EmailChange->isOptional())->toBeFalse()
        ->and(EmailType::ExportReady->isOptional())->toBeTrue();
});
