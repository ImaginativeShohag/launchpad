<?php

namespace App\Support\Google;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/** @internal */
final class CachePoolItem implements CacheItemInterface
{
    private ?DateTimeInterface $expiry = null;

    public function __construct(private readonly string $key, private mixed $value, private readonly bool $hit) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiry = $expiration;

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->expiry = match (true) {
            $time === null => null,
            $time instanceof DateInterval => (new \DateTimeImmutable)->add($time),
            default => (new \DateTimeImmutable)->modify("+{$time} seconds"),
        };

        return $this;
    }

    public function ttlSeconds(int $default): int
    {
        return $this->expiry === null ? $default : $this->expiry->getTimestamp() - time();
    }
}
