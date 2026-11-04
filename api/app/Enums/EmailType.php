<?php

namespace App\Enums;

enum EmailType: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';
    case EmailChange = 'email_change';
    case ReviewOutcome = 'review_outcome';
    case LaunchReminder = 'launch_reminder';
    case ProductLive = 'product_live';
    case NewComment = 'new_comment';
    case DailyTop5 = 'daily_top5';
    case WeeklyBest = 'weekly_best';
    case ExportReady = 'export_ready';

    /**
     * The types a member can switch off, in the order the preference API lists them. The single source for the
     * preference API, the unsubscribe rule, the send-time check and the database CHECK. `ExportReady` is appended last, so the
     * order of the first six is unchanged.
     *
     * @return list<self>
     */
    public static function preferenceTypes(): array
    {
        return [self::ReviewOutcome, self::LaunchReminder, self::ProductLive, self::NewComment, self::DailyTop5, self::WeeklyBest, self::ExportReady];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    public function isOptional(): bool
    {
        return in_array($this, self::preferenceTypes(), true);
    }
}
