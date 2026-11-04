<?php

namespace App\Support\Google;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * Minimal PSR-6 pool over Laravel's cache, so Google's certificate set is shared by all
 * workers instead of being downloaded on every sign-in. Values are serialized here (the
 * library stores DateTime objects) so cache.serializable_classes cannot break them.
 */
final class LaravelCachePool implements CacheItemPoolInterface
{
    private static int $lastReport = 0;

    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function __construct(private readonly Repository $cache, private readonly int $defaultTtl = 3600) {}

    public function getItem(string $key): CacheItemInterface
    {
        try {
            $stored = $this->cache->get($this->key($key));
        } catch (Throwable $e) {
            $this->failed($e);

            return new CachePoolItem($key, null, false);
        }

        if (is_string($stored)) {
            // Only plain data and dates are ever stored; anything else (corrupt, planted) is a miss.
            $value = @unserialize($stored, ['allowed_classes' => [DateTime::class, DateTimeImmutable::class, DateTimeZone::class]]);

            if (is_array($value) && array_key_exists('v', $value)) {
                return new CachePoolItem($key, $value['v'], true);
            }
        }

        return new CachePoolItem($key, null, false);
    }

    /** @return iterable<string, CacheItemInterface> */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        return false; // The shared Laravel cache is never flushed from here.
    }

    public function deleteItem(string $key): bool
    {
        try {
            return $this->cache->forget($this->key($key));
        } catch (Throwable $e) {
            $this->failed($e);

            return false;
        }
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $ttl = $item instanceof CachePoolItem ? $item->ttlSeconds($this->defaultTtl) : $this->defaultTtl;

        if ($ttl <= 0) {
            return $this->deleteItem($item->getKey());
        }

        try {
            return $this->cache->put($this->key($item->getKey()), serialize(['v' => $item->get()]), $ttl);
        } catch (Throwable $e) {
            $this->failed($e);

            return false;
        }
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];

        return true;
    }

    /** A broken cache must behave like an empty one (certificates are fetched again); report it at most once a minute. */
    private function failed(Throwable $e): void
    {
        if (time() - self::$lastReport >= 60) {
            self::$lastReport = time();
            report($e);
        }
    }

    private function key(string $key): string
    {
        return 'psr6:'.sha1($key);
    }
}
