<?php

namespace App\Enums;

/** How staff closed a risk signal. */
enum RiskOutcome: string
{
    case Confirmed = 'confirmed';
    case Dismissed = 'dismissed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $outcome) => $outcome->value, self::cases());
    }
}
