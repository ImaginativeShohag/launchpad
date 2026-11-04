<?php

namespace App\Http\Requests\Concerns;

trait NormalisesUrls
{
    /** Lowercases scheme and host (they are case-insensitive); path and query keep their case. */
    protected function normaliseUrl(string $url): string
    {
        return preg_replace_callback(
            '~\A([a-z][a-z0-9+.-]*)://([^/?#]*)~i',
            fn (array $m) => strtolower($m[1]).'://'.strtolower($m[2]),
            $url,
        ) ?? $url;
    }
}
