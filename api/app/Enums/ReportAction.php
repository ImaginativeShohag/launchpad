<?php

namespace App\Enums;

/** What staff do with a reported target. One action closes every open report of the target. */
enum ReportAction: string
{
    case RemoveComment = 'remove_comment';
    case TakeDown = 'take_down';
    case Dismiss = 'dismiss';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $action) => $action->value, self::cases());
    }

    /** The two actions that change the target need a staff-written reason. */
    public function needsNote(): bool
    {
        return $this !== self::Dismiss;
    }

    /** The report type this action is valid for; null (dismiss) means both. */
    public function forType(): ?string
    {
        return match ($this) {
            self::RemoveComment => 'comment',
            self::TakeDown => 'product',
            self::Dismiss => null,
        };
    }
}
