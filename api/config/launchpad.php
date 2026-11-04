<?php

return [
    'web_url' => env('WEB_URL', 'http://localhost:5173'),

    // Secret key for HMAC-hashing IPs and device ids. Never store them raw.
    'hmac_key' => env('LAUNCHPAD_HMAC_KEY'),

    'avatar_disk' => env('AVATAR_DISK', 'public'),

    // Logos and screenshots; separate from avatars so each can move to its own bucket.
    'product_disk' => env('PRODUCT_DISK', 'public'),

    // The cap on one search statement; past it the endpoint answers 503 with Retry-After (SQLSTATE 57014).
    'search_timeout_ms' => (int) env('SEARCH_TIMEOUT_MS', 2000),

    // Page view counting (POST /products/{id}/view). Counted in Redis (the dedicated `views` connection), flushed to Postgres
    // each minute. `dedupe_ttl` is 25 hours so a UTC day's key outlives the day; the caps are per product and day.
    'views' => [
        'redis_connection' => 'views',
        // Only the beacon (ViewCounters::record) uses this one: same database, 0.25 s timeouts, no retries.
        'beacon_connection' => 'views_beacon',
        'per_ip_product_day_cap' => 25,
        'max_sources_per_day' => 50,
        'dedupe_ttl' => 90000,
        // Lower-case substrings of the user agent that are never counted (an empty agent is not counted either). `bot` is
        // special: it matches as a whole word (`bot/1.0`) or after a lower-case letter as `bot`/`Bot` (Googlebot, AhrefsBot), never
        // inside an all-caps brand such as the CUBOT handsets.
        'bot_agents' => [
            'bot', 'crawl', 'spider', 'slurp', 'headless', 'preview', 'curl', 'wget', 'python-requests', 'httpclient',
            'facebookexternalhit', 'embedly', 'monitor', 'okhttp', 'axios', 'node-fetch', 'go-http-client', 'libwww',
            'scrapy', 'lighthouse', 'pingdom', 'whatsapp', 'telegram',
        ],
    ],

    // The founder dashboard reads. A short server-side cache per (product, endpoint, query) protects the database from a
    // refreshing page; 0 bypasses it (phpunit.xml pins 0). Never shared between products, never holds personal data.
    'dashboard' => [
        'cache_seconds' => (int) env('DASHBOARD_CACHE_SECONDS', 30),
    ],

    'verification_ttl_minutes' => 60,
    'email_change_ttl_minutes' => 60,
    'recent_login_minutes' => 10,
    'token_ttl_days' => 90,

    // The mail outbox (launchpad:send-emails). Constants, not admin settings. `backoff_minutes` has one wait per retry:
    // `max_attempts - 1` entries (1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h, about two days in all).
    'outbox' => [
        'batch' => 50,
        'budget_seconds' => 50,
        'lease_minutes' => 10,
        'max_attempts' => 9,
        'breaker' => 5,
        'backoff_minutes' => [1, 5, 15, 60, 180, 360, 720, 1440],
    ],

    'reserved_handles' => [
        'about', 'admin', 'administrator', 'api', 'app', 'help', 'launchpad', 'me',
        'new', 'null', 'p', 'root', 'settings', 'staff', 'support', 'system',
        'topics', 'u', 'undefined', 'user', 'users',
    ],

    // Product slugs that would collide with a route or a page. `deleted-` is reserved by ProductSlug itself.
    'reserved_product_slugs' => [
        'about', 'account', 'admin', 'api', 'edit', 'explore', 'feed', 'help', 'launchpad', 'login', 'me', 'new', 'null',
        'p', 'products', 'register', 'search', 'settings', 'staff', 'submit', 'support', 'topics', 'u', 'undefined', 'users',
    ],
];
