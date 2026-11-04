<?php

namespace App\Support\Mail;

use App\Mail\UnrenderableMail;

/** The base URL mail links are built on. In production a localhost or non-https base would mail dead or downgraded links: refuse to render instead. */
final class LinkBase
{
    /** @throws UnrenderableMail */
    public static function checked(string $url): string
    {
        if (app()->isProduction()) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || preg_match('/\A127\.\d+\.\d+\.\d+\z/', $host) === 1 || $host === '0.0.0.0';

            if ($scheme !== 'https' || $local) {
                throw new UnrenderableMail('The mail link base URL is not a public https URL.');
            }
        }

        return rtrim($url, '/');
    }

    public static function web(): string
    {
        return self::checked((string) config('launchpad.web_url'));
    }

    public static function api(): string
    {
        return self::checked((string) config('app.url'));
    }
}
