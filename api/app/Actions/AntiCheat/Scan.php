<?php

namespace App\Actions\AntiCheat;

use Carbon\CarbonImmutable;

/**
 * What one rule call looks at: a chunk of products, a chunk of voters, or the whole run. `now` is the run's single clock
 * read (UTC, whole seconds), so every window of the run is measured from the same instant.
 */
final readonly class Scan
{
    /**
     * @param  list<string>  $productIds
     * @param  list<string>  $userIds
     * @param  list<string>  $touchedUserIds  members named by findings earlier in this run (run scope only)
     */
    private function __construct(public CarbonImmutable $now, public array $productIds, public array $userIds, public array $touchedUserIds = []) {}

    /** @param  list<string>  $productIds a chunk of at most 50 */
    public static function products(CarbonImmutable $now, array $productIds): self
    {
        return new self($now, $productIds, []);
    }

    /** @param  list<string>  $userIds a chunk of at most 500 */
    public static function users(CarbonImmutable $now, array $userIds): self
    {
        return new self($now, [], $userIds);
    }

    /** @param  list<string>  $touchedUserIds */
    public static function run(CarbonImmutable $now, array $touchedUserIds = []): self
    {
        return new self($now, [], [], $touchedUserIds);
    }
}
