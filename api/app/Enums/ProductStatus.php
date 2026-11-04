<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';
    case TakenDown = 'taken_down';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /**
     * The lifecycle graph (spec section 5). Withdraw is "back to draft" from pending, approved and scheduled.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft, self::ChangesRequested => [self::Pending],
            self::Pending => [self::Approved, self::Rejected, self::ChangesRequested, self::Draft],
            self::Approved => [self::Scheduled, self::Draft],
            self::Scheduled => [self::Live, self::Draft],
            self::Live => [self::TakenDown],
            self::TakenDown => [self::Live],
            self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * Statuses in which the maker owns something that is, or is about to be, public. They block account deletion.
     *
     * @return list<self>
     */
    public static function blockingDeletion(): array
    {
        return [self::Pending, self::Approved, self::Scheduled, self::Live];
    }
}
