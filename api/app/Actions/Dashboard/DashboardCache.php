<?php

namespace App\Actions\Dashboard;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A short server-side cache in front of the dashboard reads. The key holds the product id, the endpoint and a hash of the
 * (sorted) query and of the product's status (a take-down or restore must not sit behind a stale entry): no member id, so a maker and staff share an entry (authorisation always runs first), and no entry is
 * shared between products. A TTL of 0 computes directly. A cache failure is reported and the answer is computed: the cache
 * must never fail the request.
 */
final class DashboardCache
{
    /**
     * @param  array<string, mixed>  $query
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public function remember(string $productId, string $endpoint, array $query, Closure $compute, string $state = ''): array
    {
        $ttl = (int) config('launchpad.dashboard.cache_seconds');

        if ($ttl <= 0) {
            return $compute();
        }

        ksort($query);
        $key = 'dash:'.$productId.':'.$endpoint.':'.sha1((string) json_encode([$query, $state]));

        try {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return $hit;
            }
        } catch (Throwable $e) {
            report($e);

            return $compute();
        }

        // Computed once, outside the try: a failing read is the caller's error, not a cache failure to retry.
        $value = $compute();

        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable $e) {
            report($e);
        }

        return $value;
    }
}
