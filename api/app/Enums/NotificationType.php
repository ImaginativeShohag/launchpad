<?php

namespace App\Enums;

/** The in-site notifications (the bell). Digests are email only and have no case here. */
enum NotificationType: string
{
    case ReviewApproved = 'review_approved';
    case ReviewRejected = 'review_rejected';
    case ReviewChangesRequested = 'review_changes_requested';
    case LaunchReminder = 'launch_reminder';
    case ProductLive = 'product_live';
    case NewComment = 'new_comment';
    case ExportReady = 'export_ready';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    /** The email type that carries this notification (the three review outcomes share one preference). */
    public function emailType(): EmailType
    {
        return match ($this) {
            self::ReviewApproved, self::ReviewRejected, self::ReviewChangesRequested => EmailType::ReviewOutcome,
            self::LaunchReminder => EmailType::LaunchReminder,
            self::ProductLive => EmailType::ProductLive,
            self::NewComment => EmailType::NewComment,
            self::ExportReady => EmailType::ExportReady,
        };
    }

    public function messageKey(): string
    {
        return 'notifications.'.$this->value;
    }
}
