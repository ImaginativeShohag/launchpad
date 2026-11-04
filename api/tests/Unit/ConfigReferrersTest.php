<?php

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;

it('pins the view counting settings', function () {
    // Given the application config
    // When the views block is read
    // Then it holds exactly the documented values
    expect(config('launchpad.views'))->toBe([
        'redis_connection' => 'views',
        'beacon_connection' => 'views_beacon',
        'per_ip_product_day_cap' => 25,
        'max_sources_per_day' => 50,
        'dedupe_ttl' => 90000,
        'bot_agents' => [
            'bot', 'crawl', 'spider', 'slurp', 'headless', 'preview', 'curl', 'wget', 'python-requests', 'httpclient',
            'facebookexternalhit', 'embedly', 'monitor', 'okhttp', 'axios', 'node-fetch', 'go-http-client', 'libwww',
            'scrapy', 'lighthouse', 'pingdom', 'whatsapp', 'telegram',
        ],
    ]);
});

it('keeps the allowlist well formed: lower-case dotted suffixes, none under two keys, known categories', function () {
    // Given the allowlist
    $sources = config('referrers.sources');

    // When every suffix is collected
    $all = [];
    foreach ($sources as $key => $entry) {
        expect($key)->toMatch('/\A[a-z_]+\z/')->not->toBeIn(['direct', 'launchpad', 'other']);
        expect($entry['category'])->toBeIn(['search', 'social']);
        foreach ($entry['hosts'] as $host) {
            expect($host)->toBe(strtolower($host))->toContain('.');
            $all[] = $host;
        }
    }

    // Then no suffix is listed twice
    expect($all)->toBe(array_values(array_unique($all)));
});

it('runs the tests against Redis database 15 so a flush can never touch development data', function () {
    // Given the phpunit environment
    // When the views connection is read
    // Then it is database 15 on its own connection
    expect((string) config('database.redis.views.database'))->toBe('15');
    expect(config('launchpad.views.redis_connection'))->toBe('views');
});

it('refuses to flush Redis when the configured database is not 15', function () {
    // Given a views connection pointed at the development database
    config(['database.redis.views.database' => '2']);

    // When the reset helper runs
    // Then it throws before touching Redis
    expect(fn () => viewRedisReset())->toThrow(RuntimeException::class, 'must be 15');
});

it('points the beacon connection at the same Redis database as the flush connection, with short timeouts and no retries, and no url key', function () {
    // Given the two view connections
    $views = config('database.redis.views');
    $beacon = config('database.redis.views_beacon');

    // When compared
    // Then host, port and database agree (15 under phpunit), the beacon is fast and does not retry, and neither has a url
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
        expect($beacon[$key])->toBe($views[$key]);
    }
    expect((string) $beacon['database'])->toBe('15')->and($beacon['timeout'])->toBe(0.25)->and($beacon['read_timeout'])->toBe(0.25)->and($beacon['max_retries'])->toBe(0);
    expect($views['timeout'])->toBeGreaterThan(0.25)->and($views['read_timeout'])->toBeGreaterThan(0.25);
    expect($views)->not->toHaveKey('url')->and($beacon)->not->toHaveKey('url');
});

it('refuses to flush when the live connection is not on database 15 even though the config says 15', function () {
    // Given a Redis manager whose views connection really talks to database 2 while the config still says 15
    $config = config('database.redis');
    $config['views']['database'] = '2';
    $this->app->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', $config));
    Redis::clearResolvedInstance('redis');

    // When the reset helper runs
    // Then it refuses on the live database number, before any flush
    expect(fn () => viewRedisReset())->toThrow(RuntimeException::class, 'live Redis connection');
});
