<?php

/*
| Where a counted view came from. The referrer URL is reduced to a host and the host to one of these keys (App\Actions\Views\ReferrerSource).
| `hosts` are lower-case suffixes matched on a label boundary (`t.co` matches `t.co` and `a.t.co`, never `not.co`). No suffix is
| listed under two keys. The fixed keys `direct`, `launchpad` and `other` are not listed here (they are not hosts).
*/

// Config files load alphabetically, so `launchpad` is ready here but `sanctum` is not: read the same two env values it does.
$own = [];
foreach ([
    parse_url((string) env('WEB_URL', 'http://localhost:5173'), PHP_URL_HOST),
    ...array_map(
        fn (string $d): string => preg_replace('/:\d+\z/', '', trim($d)) ?? '',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')),
    ),
] as $host) {
    if (is_string($host) && $host !== '') {
        $own[] = preg_replace('/\Awww\./', '', strtolower($host));
    }
}

return [
    // Computed at load for reference; ReferrerSource recomputes from the live config on every call, so tests may change it.
    'own_hosts' => array_values(array_unique($own)),

    'sources' => [
        'google' => ['category' => 'search', 'hosts' => [
            'google.com', 'google.co.uk', 'google.com.bd', 'google.de', 'google.fr', 'google.es', 'google.it', 'google.nl',
            'google.ca', 'google.com.au', 'google.co.in', 'google.co.jp', 'google.com.br', 'google.com.mx', 'google.ru',
            'google.pl', 'google.com.tr', 'google.se', 'google.ch', 'google.at', 'google.be', 'google.ie', 'google.pt',
        ]],
        'bing' => ['category' => 'search', 'hosts' => ['bing.com']],
        'duckduckgo' => ['category' => 'search', 'hosts' => ['duckduckgo.com']],
        'ecosia' => ['category' => 'search', 'hosts' => ['ecosia.org']],
        'yahoo' => ['category' => 'search', 'hosts' => ['search.yahoo.com', 'yahoo.com']],

        'x' => ['category' => 'social', 'hosts' => ['x.com', 'twitter.com', 't.co']],
        'facebook' => ['category' => 'social', 'hosts' => ['facebook.com', 'fb.com', 'l.facebook.com', 'm.facebook.com']],
        'linkedin' => ['category' => 'social', 'hosts' => ['linkedin.com', 'lnkd.in']],
        'reddit' => ['category' => 'social', 'hosts' => ['reddit.com', 'redd.it']],
        'hacker_news' => ['category' => 'social', 'hosts' => ['news.ycombinator.com']],
        'youtube' => ['category' => 'social', 'hosts' => ['youtube.com', 'youtu.be']],
        'mastodon' => ['category' => 'social', 'hosts' => ['mastodon.social']],
        'bluesky' => ['category' => 'social', 'hosts' => ['bsky.app']],
        'github' => ['category' => 'social', 'hosts' => ['github.com']],
    ],
];
