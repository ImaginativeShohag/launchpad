<?php

use App\Support\ViewCounters;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable(VIEW_NOON.' UTC'));
});

it('counts a first sighting: both hash fields start at one', function () {
    // Given an empty Redis database
    // When a view is recorded
    $result = viewRecord('P1', 'd1', 'ip1', 'google');

    // Then it is counted and the hour and referrer fields hold 1
    $hour = intdiv(viewNow()->getTimestamp(), 3600) * 3600;
    expect($result)->toBe(ViewCounters::COUNTED);
    expect(viewPendingHash())->toBe(["p|P1|{$hour}" => '1', 'r|P1|2026-10-21|google' => '1']);
});

it('dedupes the same device on the same day without changing anything', function () {
    // Given a counted view
    viewRecord();
    $before = viewPendingHash();

    // When the same device is seen again
    $result = viewRecord();

    // Then it is deduped and the hash is unchanged
    expect($result)->toBe(ViewCounters::DEDUPED)->and(viewPendingHash())->toBe($before);
});

it('keeps the dedupe key for 25 hours', function () {
    // Given a counted view
    viewRecord('P1', 'd1');

    // When the dedupe key's time to live is read
    $keys = viewRedis()->keys('*view:P1:20261021:d1');
    $ttl = viewRedis()->ttl(preg_replace('/\A'.preg_quote((string) config('database.redis.options.prefix'), '/').'/', '', $keys[0]));

    // Then it is between 89,990 and 90,000 seconds
    expect($keys)->toHaveCount(1)->and($ttl)->toBeGreaterThanOrEqual(89990)->toBeLessThanOrEqual(90000);
});

it('counts the same device again on a new UTC day', function () {
    // Given a counted view today
    viewRecord();

    // When the next UTC day begins
    $this->travelTo(new DateTimeImmutable('2026-10-22 00:00:05 UTC'));

    // Then the same device counts again
    expect(viewRecord())->toBe(ViewCounters::COUNTED);
    expect(array_sum(array_map('intval', array_filter(viewPendingHash(), fn ($v, $k) => str_starts_with($k, 'r|'), ARRAY_FILTER_USE_BOTH))))->toBe(2);
});

it('counts the 25th distinct device of one IP and caps the 26th, which leaves the hash unchanged', function () {
    // Given 25 distinct devices from one IP
    foreach (range(1, 25) as $i) {
        expect(viewRecord('P1', "d{$i}", 'ipA'))->toBe(ViewCounters::COUNTED);
    }
    $before = viewPendingHash();

    // When a 26th distinct device arrives
    $result = viewRecord('P1', 'd26', 'ipA');

    // Then it is capped, nothing changed, and a repeat of a counted device is deduped, not capped
    expect($result)->toBe(ViewCounters::CAPPED)->and(viewPendingHash())->toBe($before);
    expect(viewRecord('P1', 'd1', 'ipA'))->toBe(ViewCounters::DEDUPED);
});

it('answers a capped device CAPPED again and creates no key for it', function () {
    // Given a full cap and a capped device
    foreach (range(1, 25) as $i) {
        viewRecord('P1', "d{$i}", 'ipA');
    }
    expect(viewRecord('P1', 'late', 'ipA'))->toBe(ViewCounters::CAPPED);

    // When the capped device returns
    // Then it is capped again (it never became a counted device) and there is still no key for it
    expect(viewRecord('P1', 'late', 'ipA'))->toBe(ViewCounters::CAPPED);
    expect(viewRedis()->keys('*view:P1:20261021:late'))->toBe([]);
});

it('creates no view keys for capped traffic however many devices rotate', function () {
    // Given an address at its cap
    foreach (range(1, 25) as $i) {
        viewRecord('P1', "d{$i}", 'ipA');
    }
    $keys = fn () => count(viewRedis()->keys('*'));
    $before = $keys();
    $hash = viewPendingHash();

    // When 200 rotating devices from that address arrive
    foreach (range(1, 200) as $i) {
        expect(viewRecord('P1', "rot{$i}", 'ipA'))->toBe(ViewCounters::CAPPED);
    }

    // Then no key was added and nothing was counted
    expect($keys())->toBe($before)->and(viewPendingHash())->toBe($hash);
});

it('sets the IP counter time to live only on its first increment', function () {
    // Given a counted view from an IP
    viewRecord('P1', 'd1', 'ipA');
    $prefix = (string) config('database.redis.options.prefix');
    $key = fn () => preg_replace('/\A'.preg_quote($prefix, '/').'/', '', viewRedis()->keys('*viewip:P1:20261021:ipA')[0]);
    $first = viewRedis()->ttl($key());

    // When 100 seconds pass and a second device arrives
    $this->travel(100)->seconds();
    viewRedis()->expire($key(), 5000);
    viewRecord('P1', 'd2', 'ipA', 'direct', viewNow());

    // Then the second increment did not refresh the time to live
    expect($first)->toBeGreaterThan(89990)->and(viewRedis()->ttl($key()))->toBeLessThanOrEqual(5000);
});

it('gives two products and two IPs independent caps', function () {
    // Given 25 devices from ipA on P1
    foreach (range(1, 25) as $i) {
        viewRecord('P1', "d{$i}", 'ipA');
    }

    // When another product, and another IP, are used
    // Then neither is capped, while the first pair is
    expect(viewRecord('P2', 'x1', 'ipA'))->toBe(ViewCounters::COUNTED);
    expect(viewRecord('P1', 'x2', 'ipB'))->toBe(ViewCounters::COUNTED);
    expect(viewRecord('P1', 'x3', 'ipA'))->toBe(ViewCounters::CAPPED);
});

it('counts one device exactly once over 50 calls', function () {
    // Given one device
    // When it is recorded 50 times
    $results = array_map(fn () => viewRecord('P1', 'same'), range(1, 50));

    // Then exactly one call counted
    expect(array_count_values($results))->toBe([ViewCounters::COUNTED => 1, ViewCounters::DEDUPED => 49]);
});

it('rotates nothing when nothing is pending', function () {
    // Given an empty database
    // When it is rotated twice
    // Then both answers are null and no batch is registered
    expect(viewCounters()->rotate())->toBeNull()->and(viewCounters()->rotate())->toBeNull()->and(viewCounters()->batches())->toBe([]);
});

it('rotates the pending hash into a registered batch', function () {
    // Given a pending view
    viewRecord('P1', 'd1');
    $pending = viewPendingHash();

    // When it is rotated
    $n = viewCounters()->rotate();

    // Then the batch holds everything, is registered, pending is gone, and a second rotation is null
    expect($n)->toBeInt()->and(viewCounters()->read($n))->toBe(array_map('intval', $pending))->and(viewCounters()->batches())->toBe([$n]);
    expect(viewPendingHash())->toBe([])->and(viewCounters()->rotate())->toBeNull();
});

it('puts a view recorded after the rotation into a fresh pending hash', function () {
    // Given a rotated batch
    viewRecord('P1', 'd1');
    $n = viewCounters()->rotate();

    // When another view arrives
    viewRecord('P1', 'd2');

    // Then it lands in pending, not in the batch
    expect(array_sum(viewCounters()->read($n)))->toBe(2)->and(array_sum(array_map('intval', viewPendingHash())))->toBe(2);
});

it('lists batches in ascending order and drops one key and registration together', function () {
    // Given three rotated batches
    $ids = [];
    foreach (['d1', 'd2', 'd3'] as $device) {
        viewRecord('P1', $device);
        $ids[] = viewCounters()->rotate();
    }

    // When the middle one is dropped
    expect(viewCounters()->batches())->toBe($ids);
    viewCounters()->drop($ids[1]);

    // Then its key and registration are gone and the others remain
    expect(viewCounters()->batches())->toBe([$ids[0], $ids[2]])->and(viewCounters()->read($ids[1]))->toBe([]);
});

it('reads a missing batch as empty', function () {
    // Given no such batch
    // When it is read
    // Then it is an empty array
    expect(viewCounters()->read(999))->toBe([]);
});

it('keeps a registered batch id whose key never existed', function () {
    // Given a registration without a key (a crash between SADD and RENAME)
    viewRedis()->sadd('views:batches', 7);

    // When it is read
    // Then it is listed and empty
    expect(viewCounters()->batches())->toBe([7])->and(viewCounters()->read(7))->toBe([]);
});
