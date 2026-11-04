<?php

namespace App\Actions\Vote;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** The per-hour vote counters behind the hot score. Always called inside the product-locked transaction of the caller. */
final class HourlyBuckets
{
    /** Adds `$delta` (negative to take votes back) to the UTC hour bucket that contains `$at`, creating it when missing. */
    public static function add(string $productId, CarbonInterface $at, int $delta): void
    {
        DB::statement(
            "INSERT INTO product_hourly_stats (product_id, hour_start, votes) VALUES (?, date_trunc('hour', ?::timestamptz, 'UTC'), ?)
             ON CONFLICT (product_id, hour_start) DO UPDATE SET votes = product_hourly_stats.votes + EXCLUDED.votes",
            [$productId, $at->copy()->utc()->format('Y-m-d H:i:s.uP'), $delta],
        );
    }
}
