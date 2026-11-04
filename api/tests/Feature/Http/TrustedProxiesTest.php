<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

beforeEach(function () {
    Route::middleware('api')->get('api/v1/_ip', fn () => response()->json(['ip' => request()->ip(), 'secure' => request()->isSecure()]));
});

function viaProxy(string $proxyAddress): TestCase
{
    return test()->withServerVariables(['REMOTE_ADDR' => $proxyAddress])->withHeaders([
        'X-Forwarded-For' => '203.0.113.9',
        'X-Forwarded-Proto' => 'https',
    ]);
}

it('trusts no proxy by default, so a spoofed X-Forwarded-For is ignored', function () {
    // Given no trusted proxies (the unset env case is pinned by the parse test below)
    config(['trustedproxy.proxies' => []]);

    // When a request carries forwarding headers from an arbitrary peer
    $response = viaProxy('10.0.0.5')->getJson('/api/v1/_ip');

    // Then the peer address is the client address and the scheme is not upgraded
    expect($response->json('ip'))->toBe('10.0.0.5');
    expect($response->json('secure'))->toBeFalse();
});

it('honours forwarding headers only from the configured proxy ranges', function (string $peer, string $ip, bool $secure) {
    // Given a trusted proxy range
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    // When a request arrives from the given peer carrying forwarding headers
    $response = viaProxy($peer)->getJson('/api/v1/_ip');

    // Then only a trusted proxy may set the client address and scheme
    expect($response->json('ip'))->toBe($ip);
    expect($response->json('secure'))->toBe($secure);
})->with([
    'inside the range' => ['10.0.0.5', '203.0.113.9', true],
    'outside the range' => ['198.51.100.7', '198.51.100.7', false],
]);

it('parses the TRUSTED_PROXIES list and never accepts a wildcard', function (?string $env, array $expected) {
    // Given an environment value
    $previous = [getenv('TRUSTED_PROXIES'), $_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null];
    $set = function (?string $value): void {
        if ($value === null) {
            putenv('TRUSTED_PROXIES');
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        } else {
            putenv('TRUSTED_PROXIES='.$value);
            $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
        }
    };
    $set($env);

    try {
        // When the config file is evaluated
        $proxies = (require config_path('trustedproxy.php'))['proxies'];
    } finally {
        $set($previous[0] === false ? $previous[1] ?? $previous[2] : $previous[0]);
    }

    // Then the list is trimmed and wildcards are dropped
    expect($proxies)->toBe($expected);
})->with([
    'unset' => [null, []],
    'empty' => ['', []],
    'list' => [' 10.0.0.0/8 , 192.168.1.1 ', ['10.0.0.0/8', '192.168.1.1']],
    'star' => ['*', []],
    'double star' => ['**', []],
    'star among others' => ['10.0.0.1,*', ['10.0.0.1']],
    'ipv4 default route' => ['0.0.0.0/0', []],
    'ipv6 default route' => ['::/0', []],
    'REMOTE_ADDR token' => ['remote_addr', []],
    'default route among others' => ['10.0.0.1,0.0.0.0/0', ['10.0.0.1']],
]);
