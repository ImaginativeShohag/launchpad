<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Every Redis call of the view counting goes through this class (bound as a singleton, so a test can swap in a subclass
 * that throws). Nothing here stores a person: keys hold HMACs, values hold counts.
 *
 * Keys, all on the `views` connection: `view:{product}:{Ymd}:{device}` (dedupe), `viewip:{product}:{Ymd}:{ip}` (per-IP
 * cap counter), `views:pending` (hash), `views:seq` (counter), `views:batches` (set of rotated, not yet dropped batch
 * ids) and `views:flushing:{n}` (hash) and `views:claim:{n}` (a flush runner's claim on a batch). Hash fields are `p|{product}|{hour epoch}` and `r|{product}|{Y-m-d}|{source}`.
 */
class ViewCounters
{
    public const DEDUPED = 0;

    public const COUNTED = 1;

    public const CAPPED = 2;

    private const PENDING = 'views:pending';

    private const SEQ = 'views:seq';

    private const BATCHES = 'views:batches';

    public const CLAIM_SECONDS = 600;

    // An address already at its cap creates no dedupe key (capped traffic must not grow Redis): a known device is still
    // answered DEDUPED, a new one CAPPED. Otherwise dedupe the device for the day (SET NX), then count a first sighting against the per-IP cap, then both HINCRBYs. One
    // script, so a crash or a concurrent request can never leave a half-counted view. Every key is in KEYS (phpredis
    // applies the global prefix to KEYS, not to ARGV). SET NX answers a nil reply (false in Lua) when the key exists.
    private const RECORD = <<<'LUA'
        local used = tonumber(redis.call('GET', KEYS[2]) or '0')
        if used >= tonumber(ARGV[2]) then
            if redis.call('EXISTS', KEYS[1]) == 1 then return 0 end
            return 2
        end
        if not redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[1]) then return 0 end
        local n = redis.call('INCR', KEYS[2])
        if n == 1 then redis.call('EXPIRE', KEYS[2], ARGV[1]) end
        if n > tonumber(ARGV[2]) then return 2 end
        redis.call('HINCRBY', KEYS[3], ARGV[3], 1)
        redis.call('HINCRBY', KEYS[3], ARGV[4], 1)
        return 1
        LUA;

    /** @return int one of COUNTED, DEDUPED, CAPPED */
    public function record(string $productId, string $deviceHash, string $ipHash, CarbonImmutable $now, string $source): int
    {
        $now = $now->utc();
        $day = $now->format('Ymd');
        $hour = intdiv($now->getTimestamp(), 3600) * 3600;

        $result = $this->redis((string) config('launchpad.views.beacon_connection'))->eval(
            self::RECORD, 3,
            "view:{$productId}:{$day}:{$deviceHash}", "viewip:{$productId}:{$day}:{$ipHash}", self::PENDING,
            (int) config('launchpad.views.dedupe_ttl'), (int) config('launchpad.views.per_ip_product_day_cap'),
            "p|{$productId}|{$hour}", "r|{$productId}|{$now->format('Y-m-d')}|{$source}",
        );

        return is_int($result) ? $result : (int) (is_numeric($result) ? $result : self::DEDUPED);
    }

    /**
     * Moves `views:pending` to `views:flushing:{n}` and returns n; null when there is nothing pending. The id is
     * registered first, so a crash between the two steps leaves a registered id without a key (read() answers empty and
     * the flush drops it), never a key nobody knows about. No SCAN: it is prefix-sensitive.
     */
    public function rotate(): ?int
    {
        $redis = $this->redis();

        if ((int) $redis->exists(self::PENDING) === 0) {
            return null;
        }

        $n = (int) $redis->incr(self::SEQ);
        $redis->sadd(self::BATCHES, $n);

        try {
            $redis->rename(self::PENDING, "views:flushing:{$n}");
        } catch (Throwable $e) {
            // The hash vanished between EXISTS and RENAME (or RENAME failed): unregister. If Redis itself is down this
            // call throws too, which is the right outcome. Nothing is decided from the message.
            $redis->srem(self::BATCHES, $n);

            return null;
        }

        return $n;
    }

    /** @return list<int> batch ids still registered (rotated, not yet dropped), ascending */
    public function batches(): array
    {
        $ids = array_map('intval', (array) $this->redis()->smembers(self::BATCHES));
        sort($ids);

        return $ids;
    }

    /** @return array<string, int> field => count of batch n; empty when the key is gone */
    public function read(int $n): array
    {
        return array_map('intval', (array) $this->redis()->hgetall("views:flushing:{$n}"));
    }

    /**
     * Claims a batch for one flush runner (SET NX EX): false when another runner holds it. Two overlapping runs (the
     * scheduler lock expired under a slow run, or a manual run) would otherwise both read the batch and write it twice.
     * A crashed runner's claim expires by itself, after which a later run retries the batch.
     */
    public function claim(int $n): bool
    {
        return (bool) $this->redis()->set("views:claim:{$n}", '1', 'EX', self::CLAIM_SECONDS, 'NX');
    }

    public function release(int $n): void
    {
        $this->redis()->del("views:claim:{$n}");
    }

    public function drop(int $n): void
    {
        $redis = $this->redis();
        $redis->del("views:flushing:{$n}");
        $redis->srem(self::BATCHES, $n);
    }

    /** @return array<string, string> field => count of `views:pending` (tests, diagnostics) */
    public function pending(): array
    {
        return array_map('strval', (array) $this->redis()->hgetall(self::PENDING));
    }

    private function redis(?string $name = null): PhpRedisConnection
    {
        $connection = Redis::connection($name ?? (string) config('launchpad.views.redis_connection'));
        assert($connection instanceof PhpRedisConnection);

        return $connection;
    }
}
