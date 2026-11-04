<?php

namespace App\Enums;

/** The states of an outbox row. `failed` is final (gave up or permanent error); a retryable failure goes back to `queued`. */
enum EmailStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /** A terminal row never changes again (and is what the retention purge removes). */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Suppressed], true);
    }
}
