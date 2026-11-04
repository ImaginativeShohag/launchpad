<?php

namespace App\Enums;

enum ReviewDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $decision) => $decision->value, self::cases());
    }

    public function toStatus(): ProductStatus
    {
        return match ($this) {
            self::Approved => ProductStatus::Approved,
            self::Rejected => ProductStatus::Rejected,
            self::ChangesRequested => ProductStatus::ChangesRequested,
        };
    }

    /** Rejections and change requests are shown to the founder, so they need a reason. */
    public function needsNote(): bool
    {
        return $this !== self::Approved;
    }
}
