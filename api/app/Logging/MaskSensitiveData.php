<?php

namespace App\Logging;

use App\Support\MaskEmail;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Logger;
use Monolog\LogRecord;
use Stringable;
use Throwable;

/** Logging "tap": masks email addresses and redacts secrets (by key and by pattern). */
class MaskSensitiveData
{
    /** Keys containing any of these (case-insensitive) have their value redacted. */
    private const SENSITIVE_SUBSTRINGS = [
        'password', 'secret', 'token', 'signature', 'api_key', 'apikey', 'authorization',
        'cookie', 'x-xsrf', 'x-device-id', 'lp_did',
    ];

    /** Keys equal to any of these (case-insensitive) have their value redacted. */
    private const SENSITIVE_EXACT = ['code', 'state'];

    private const MAX_DEPTH = 8;

    /** Laravel passes its own logger wrapper to taps; plain Monolog is accepted for unit tests. */
    public function __invoke(LaravelLogger|Logger $logger): void
    {
        $monolog = $logger instanceof LaravelLogger ? $logger->getLogger() : $logger;

        if (! $monolog instanceof Logger) {
            return;
        }

        $monolog->pushProcessor(function (LogRecord $record): LogRecord {
            // Fail closed: a masking error must never break the request or leak the original data.
            try {
                return $record->with(
                    message: $this->maskString($record->message),
                    context: $this->scrub($record->context),
                    extra: $this->scrub($record->extra),
                );
            } catch (Throwable) {
                return $record->with(message: '[log masking failed]', context: [], extra: []);
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = '[redacted]';
            } elseif ($depth >= self::MAX_DEPTH && (is_array($value) || $value instanceof Throwable)) {
                $data[$key] = '[truncated]';
            } else {
                $data[$key] = $this->scrubValue($value, $depth + 1);
            }
        }

        return $data;
    }

    private function scrubValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_array($value) => $this->scrub($value, $depth),
            is_string($value) => $this->maskString($value),
            $value instanceof Throwable => [
                'class' => $value::class,
                'message' => $this->maskString($value->getMessage()),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ],
            $value instanceof Stringable => $this->maskString((string) $value),
            default => $value,
        };
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUBSTRINGS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskString(string $text): string
    {
        return $this->maskEmails($this->maskSecrets($text));
    }

    private function maskSecrets(string $text): string
    {
        $text = (string) preg_replace('/((?:Bearer|Basic)\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[redacted]', $text);

        // JSON-looking secrets: "password":"..."
        $text = (string) preg_replace(
            '/("(?:password|current_password|token|id_token|access_token|secret|client_secret|authorization)"\s*:\s*")[^"]*/i',
            '$1[redacted]',
            $text,
        );

        // Query secrets, also URL-encoded (%3D, %26) or HTML-escaped (&amp;) or ;-separated.
        return (string) preg_replace(
            '/((?:[?&;]|&amp;|%26|%3F)(?:signature|token|code|state|access_token|id_token)(?:=|%3D))(?:(?!%26)[^&\s])+/i',
            '$1[redacted]',
            $text,
        );
    }

    private function maskEmails(string $text): string
    {
        return (string) preg_replace_callback(
            '/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
            fn (array $m) => MaskEmail::mask($m[0]),
            $text,
        );
    }
}
