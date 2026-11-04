<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A public https link for a profile. Strict on purpose: ASCII only (IDN hosts must be written in punycode),
 * no credentials, no IP addresses in any notation (decimal, octal, hex, short forms), no localhost or
 * single-label or reserved-suffix hosts, and a real alphabetic top-level domain. Port 443 only.
 *
 * This is a syntax check for links shown to people. DNS names that resolve to private addresses
 * (127.0.0.1.nip.io) cannot be blocked by looking at the text, so any future server-side fetch of a
 * member URL must resolve the name itself and check the resulting IP.
 */
class SafeProfileUrl implements ValidationRule
{
    private const BLOCKED_TLDS = [
        'localhost', 'local', 'localdomain', 'internal', 'lan', 'home', 'corp', 'intranet', 'svc',
        'test', 'example', 'invalid', 'onion', 'arpa',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isSafe($value)) {
            $fail('The :attribute must be a public https link.');
        }
    }

    private function isSafe(string $url): bool
    {
        // Printable ASCII only, no spaces or backslashes (parsers disagree about them).
        if ($url === '' || preg_match('/\A[\x21-\x7e]+\z/', $url) !== 1 || str_contains($url, '\\')) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        // The authority is everything between "//" and the first "/", "?" or "#"; "@" there means credentials.
        preg_match('~\A[^:]+://([^/?#]*)~', $url, $authority);

        if (isset($parts['user']) || isset($parts['pass']) || str_contains($authority[1] ?? '', '@')) {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        return $this->isPublicHost(strtolower((string) ($parts['host'] ?? '')));
    }

    private function isPublicHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        $tld = end($labels);

        // Browsers read hosts whose last label is numeric or hex (0x7f.1, 017700000001) as IPv4, so require a
        // real alphabetic TLD (or punycode), which also rules those out.
        if (preg_match('/\A(?:[a-z]{2,}|xn--[a-z0-9-]+)\z/', $tld) !== 1) {
            return false;
        }

        return ! in_array($tld, self::BLOCKED_TLDS, true);
    }
}
