<?php

namespace App\Enums;

/** The life of an export row. `queued` and `processing` are in flight, `ready` is downloadable; the rest are final. */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /** Counts against the one-export-per-product rule (the partial unique index). */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Processing, self::Ready], true);
    }

    /** Being built or waiting to be built. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }
}
