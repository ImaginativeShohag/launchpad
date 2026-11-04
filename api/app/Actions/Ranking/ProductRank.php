<?php

namespace App\Actions\Ranking;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The rank shown on a product page: the live position on today's list for a product launched today, or the frozen rank
 * of its (past) launch day. Null for everything else, including a finished day that has not been frozen yet.
 */
class ProductRank
{
    public function __construct(private readonly TodayRanking $today) {}

    /** @return array{rank: int, rank_date: string}|null */
    public function for(Product $product, CarbonImmutable $now): ?array
    {
        $date = $product->launch_date?->toDateString();

        if ($date === null) {
            return null;
        }

        $todayDate = $now->utc()->toDateString();

        if ($date === $todayDate) {
            $rank = $this->today->positionOf($now, $product);

            return $rank === null ? null : ['rank' => $rank, 'rank_date' => $date];
        }

        if ($date > $todayDate) {
            return null;
        }

        $rank = DB::table('daily_rankings')->where('launch_date', $date)->where('product_id', $product->getKey())->value('rank');

        return $rank === null ? null : ['rank' => (int) $rank, 'rank_date' => $date];
    }
}
