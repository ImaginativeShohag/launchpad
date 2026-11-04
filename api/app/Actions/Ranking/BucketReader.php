<?php

namespace App\Actions\Ranking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reads the hourly vote buckets of one UTC day for many products, one query per chunk of ids (never an unbounded IN list). */
class BucketReader
{
    public const CHUNK = 5000;

    public function __construct(private readonly int $chunk = self::CHUNK) {}

    /**
     * @param  list<string>  $productIds
     * @param  CarbonImmutable  $day  any instant of the UTC day
     * @return array<string, array<int, int>> product id => (UTC hour index => net votes)
     */
    public function forDay(array $productIds, CarbonImmutable $day): array
    {
        $dayStart = $day->utc()->startOfDay();
        $from = $dayStart->format('Y-m-d H:i:sP');
        $to = $dayStart->addDay()->format('Y-m-d H:i:sP');
        $byProduct = [];

        foreach (array_chunk($productIds, max(1, $this->chunk)) as $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));

            $rows = DB::select(
                "SELECT product_id, EXTRACT(HOUR FROM hour_start AT TIME ZONE 'UTC')::int AS hour, votes
                 FROM product_hourly_stats
                 WHERE product_id IN ({$in}) AND hour_start >= ?::timestamptz AND hour_start < ?::timestamptz",
                [...$ids, $from, $to],
            );

            foreach ($rows as $row) {
                $byProduct[(string) $row->product_id][(int) $row->hour] = (int) $row->votes;
            }
        }

        return $byProduct;
    }
}
