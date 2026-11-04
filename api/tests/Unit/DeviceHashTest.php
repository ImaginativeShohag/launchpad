<?php

use App\Support\DeviceHash;
use Illuminate\Http\Request;

it('hashes with HMAC-SHA256 and never returns the raw value', function () {
    // Given a raw IP address and the configured secret
    $ip = '203.0.113.7';

    // When it is hashed
    $hash = DeviceHash::hmac($ip);

    // Then it is a 64-character hex digest that differs from the input
    expect($hash)->toBe(hash_hmac('sha256', $ip, config('launchpad.hmac_key')));
    expect($hash)->toHaveLength(64)->not->toContain($ip);
});

it('changes the digest when the secret changes', function () {
    // Given the same value hashed under two secrets
    $first = DeviceHash::hmac('device-1');
    config(['launchpad.hmac_key' => 'a-completely-different-secret-key-1234']);

    // When it is hashed again
    $second = DeviceHash::hmac('device-1');

    // Then the digests differ
    expect($first)->not->toBe($second);
});

it('returns null for empty input and refuses to hash without a secret', function () {
    // Given empty input
    // When hashed
    // Then null comes back
    expect(DeviceHash::hmac(null))->toBeNull()->and(DeviceHash::hmac(''))->toBeNull();

    // Given no secret configured
    config(['launchpad.hmac_key' => '']);

    // When a real value is hashed
    // Then it refuses instead of using an empty key
    expect(fn () => DeviceHash::hmac('x'))->toThrow(RuntimeException::class);
});

it('refuses a secret shorter than 32 characters', function () {
    // Given a configured secret that is too short to be safe
    config(['launchpad.hmac_key' => str_repeat('k', 31)]);

    // When a real value is hashed
    // Then it refuses instead of using a weak key
    expect(fn () => DeviceHash::hmac('x'))->toThrow(RuntimeException::class);

    // Given a secret of exactly 32 characters
    config(['launchpad.hmac_key' => str_repeat('k', 32)]);

    // When hashed
    // Then it works
    expect(DeviceHash::hmac('x'))->toHaveLength(64);
});

it('hashes the request ip and returns null without one', function () {
    // Given a request from a known address and one without an address
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.9']);
    $none = Request::create('/');
    $none->server->remove('REMOTE_ADDR');

    // When the ip is hashed
    // Then the first is an HMAC digest and the second is null
    expect(DeviceHash::ip($request))->toBe(hash_hmac('sha256', '198.51.100.9', config('launchpad.hmac_key')));
    expect(DeviceHash::ip($none))->toBeNull();
});

it('hashes the device id attribute and returns null without one', function () {
    // Given a request with a device id attribute and one without
    $request = Request::create('/');
    $request->attributes->set('device_id', 'device-abcdefghijkl');

    // When the device is hashed
    // Then the first is an HMAC digest and the second is null
    expect(DeviceHash::device($request))->toBe(hash_hmac('sha256', 'device-abcdefghijkl', config('launchpad.hmac_key')));
    expect(DeviceHash::device(Request::create('/')))->toBeNull();
});

it('reduces IPv6 to its /64 for view counting and leaves IPv4 alone', function () {
    // Given addresses
    $hash = fn (string $ip) => DeviceHash::viewIp(Request::create('/x', 'POST', server: ['REMOTE_ADDR' => $ip]));

    // When hashed for views
    // Then the same /64 hashes alike, another /64 differs, IPv4-mapped equals IPv4, and the plain IP hash is unchanged
    expect($hash('2001:db8:a:b::1'))->toBe($hash('2001:db8:a:b:1234:5678:9abc:def0'))->and($hash('2001:db8:a:b::1'))->not->toBe($hash('2001:db8:a:c::1'));
    expect($hash('::ffff:10.0.0.1'))->toBe($hash('10.0.0.1'))->and($hash('10.0.0.1'))->not->toBe($hash('10.0.0.2'));
    expect(DeviceHash::prefix('10.0.0.1'))->toBe('10.0.0.1')->and(DeviceHash::prefix('2001:db8:a:b::1'))->toBe('2001:db8:a:b::/64')->and(DeviceHash::prefix(null))->toBeNull();
    expect(DeviceHash::ip(Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '2001:db8:a:b::1'])))->not->toBe($hash('2001:db8:a:b::1'));
});
