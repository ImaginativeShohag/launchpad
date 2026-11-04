<?php

namespace App\Support\Mail;

use Throwable;

/** What `email_messages.last_error` stores about a failure: the class name and a redacted, bounded message. Never an address. */
final class ErrorSummary
{
    public const MAX = 500;

    public static function of(Throwable $e): string
    {
        $message = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/', '[email]', $e->getMessage()) ?? '';
        $message = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $message) ?? '';

        return mb_substr(get_class($e).': '.trim($message), 0, self::MAX);
    }
}
