<?php

namespace App\Enums;

enum VoteStatus: string
{
    case Counted = 'counted';
    case Flagged = 'flagged';
    case Voided = 'voided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /** A flagged vote still counts until staff void it; only a voided one does not. */
    public function counts(): bool
    {
        return $this !== self::Voided;
    }
}
