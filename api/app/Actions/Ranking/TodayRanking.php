<?php

namespace App\Actions\Ranking;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** The live order of a launch day: shared by GET /today, the day pages and the rank snapshots. */
class TodayRanking
{
    public const LIMIT = 100;

    /**
     * Visible launches of the UTC day of $now, best first: score, then votes, then id (the order of
     * products_launch_date_hot_score_index for its leading columns). The list card needs the maker (with links),
     * topics only, so a feed costs the same number of queries whatever its length.
     *
     * @return Collection<int, Product>
     */
    public function products(CarbonImmutable $now, int $limit = self::LIMIT): Collection
    {
        return $this->query($now)->with(['maker.links', 'topics'])->limit($limit)->get();
    }

    /**
     * The same order as products(), reduced to id, hot_score and votes_count (no relations): for jobs that only need positions.
     *
     * @return Collection<int, Product>
     */
    public function ranking(CarbonImmutable $now, int $limit = self::LIMIT): Collection
    {
        return $this->query($now)->limit($limit)->get(['id', 'hot_score', 'votes_count']);
    }

    /**
     * The 1-based position of a product on today's list, or null when it is not on it (a draft, a taken-down, a future or
     * past launch, an unavailable maker). Built on the same private query as the feed, so visibility, maker availability and the
     * three-column order are shared: 1 + the rows that sort strictly before it. The product's score and votes are read
     * inside SQL from its own stored row, never taken from the instance passed in (which may be stale), and the position is not
     * limited to the feed's top 100.
     */
    public function positionOf(CarbonImmutable $now, Product $product): ?int
    {
        $id = $product->getKey();

        // One statement over the day's rows: how many sort before this product, and whether it is among them at all.
        $row = $this->query($now)
            ->reorder()
            ->toBase()
            ->selectRaw(<<<'SQL'
                count(*) FILTER (WHERE
                    products.hot_score > (SELECT me.hot_score FROM products AS me WHERE me.id = ?)
                    OR (products.hot_score = (SELECT me.hot_score FROM products AS me WHERE me.id = ?)
                        AND products.votes_count > (SELECT me.votes_count FROM products AS me WHERE me.id = ?))
                    OR (products.hot_score = (SELECT me.hot_score FROM products AS me WHERE me.id = ?)
                        AND products.votes_count = (SELECT me.votes_count FROM products AS me WHERE me.id = ?)
                        AND products.id < ?)
                ) AS before,
                count(*) FILTER (WHERE products.id = ?) AS found
                SQL, [$id, $id, $id, $id, $id, $id, $id])
            ->first();

        return $row === null || (int) $row->found === 0 ? null : 1 + (int) $row->before;
    }

    /** @return Builder<Product> */
    private function query(CarbonImmutable $now): Builder
    {
        return Product::visible()
            ->whereMakerAvailable()
            ->where('launch_date', $now->utc()->toDateString())
            ->orderByDesc('hot_score')
            ->orderByDesc('votes_count')
            ->orderBy('id');
    }
}
