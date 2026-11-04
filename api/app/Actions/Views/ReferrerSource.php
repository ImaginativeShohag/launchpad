<?php

namespace App\Actions\Views;

/**
 * Reduces what a browser calls `document.referrer` to a short source key and throws everything else away (path, query,
 * fragment, credentials, port). The output is always `direct`, `other`, `launchpad`, an allowlist key from
 * config/referrers.php, or a valid lower-case host of at most 80 characters. It never throws.
 */
final class ReferrerSource
{
    private const MAX_URL = 2048;

    private const MAX_HOST = 80;

    public static function from(mixed $referrer): string
    {
        if (! is_string($referrer) || $referrer === '' || strlen($referrer) > self::MAX_URL
            || ! mb_check_encoding($referrer, 'UTF-8') || preg_match('/[\x00-\x20\x7F]/', $referrer) === 1) {
            return 'direct';
        }

        $parts = parse_url($referrer);
        if ($parts === false || ! isset($parts['host']) || $parts['host'] === ''
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return 'direct';
        }

        // ASCII lower-casing only: Unicode folding would map the Kelvin sign to `k` and alias a different host. The IDN
        // conversion comes BEFORE the www. and trailing-dot strips, so a fullwidth or ideographic dot form cannot dodge them.
        $host = strtolower($parts['host']);
        if (preg_match('/[^\x00-\x7F]/', $host) === 1 || str_contains($host, 'xn--')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($ascii) || $ascii === '') {
                return 'other';
            }
            $host = strtolower($ascii);
        }
        $host = preg_replace('/\.\z/', '', $host) ?? $host;
        $host = preg_replace('/\Awww\./', '', $host) ?? $host;

        if (in_array($host, self::ownHosts(), true)) {
            return 'launchpad';
        }

        $key = self::allowlisted($host);
        if ($key !== null) {
            return $key;
        }

        return self::isValidHost($host) ? $host : 'other';
    }

    /** `direct`, `internal`, `search`, `social` or `other`; derived from the config, never stored. */
    public static function category(string $source): string
    {
        return match ($source) {
            'direct' => 'direct',
            'launchpad' => 'internal',
            default => self::configuredCategory($source),
        };
    }

    private static function configuredCategory(string $source): string
    {
        // Looked up as an array key, never as a dotted path: a stored host such as `a.b` must not walk into the config.
        $sources = (array) config('referrers.sources', []);
        $category = is_array($sources[$source] ?? null) ? ($sources[$source]['category'] ?? null) : null;

        return in_array($category, ['search', 'social'], true) ? $category : 'other';
    }

    /** @return list<string> the hosts of this site, recomputed from the live config so a test may change them */
    private static function ownHosts(): array
    {
        $candidates = [parse_url((string) config('launchpad.web_url'), PHP_URL_HOST)];
        foreach ((array) config('sanctum.stateful', []) as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $domain = trim($domain);
            // Strip a port from `host:port` but leave a bare IPv6 literal such as `::1` alone.
            $candidates[] = substr_count($domain, ':') === 1 ? preg_replace('/:\d*\z/', '', $domain) : $domain;
        }

        $hosts = [];
        foreach ($candidates as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = preg_replace('/\Awww\./', '', strtolower($host)) ?? '';
            }
        }

        return array_values(array_unique($hosts));
    }

    /** The key whose longest configured suffix matches the host on a label boundary. */
    private static function allowlisted(string $host): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach ((array) config('referrers.sources', []) as $key => $entry) {
            foreach ((array) ($entry['hosts'] ?? []) as $suffix) {
                if (! is_string($suffix) || $suffix === '' || strlen($suffix) <= $bestLength) {
                    continue;
                }
                if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                    $best = (string) $key;
                    $bestLength = strlen($suffix);
                }
            }
        }

        return $best;
    }

    private static function isValidHost(string $host): bool
    {
        if (strlen($host) > self::MAX_HOST || ! str_contains($host, '.')) {
            return false;
        }

        // Browsers parse `0x7f.1` and `2130706433` as IPv4, so the last label must be alphabetic or punycode.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        $tld = end($labels);

        return preg_match('/\A(?:[a-z]{2,63}|xn--[a-z0-9-]+)\z/', $tld) === 1;
    }
}
