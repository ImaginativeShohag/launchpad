<?php

namespace App\Actions\Ranking;

use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The list of one finished launch day: the frozen rows when they exist, else the order the freeze will write. */
class DayRanking
{
    public function __construct(private readonly FinalOrder $order, private readonly Settings $settings) {}

    /**
     * @param  CarbonImmutable  $day  a day before today (UTC)
     * @return array{frozen: bool, items: list<array{product: Product, rank: int}>} best first, at most TodayRanking::LIMIT
     */
    public function handle(CarbonImmutable $day): array
    {
        $date = $day->toDateString();

        return DB::table('daily_rankings')->where('launch_date', $date)->exists()
            ? ['frozen' => true, 'items' => $this->frozen($date)]
            : ['frozen' => false, 'items' => $this->computed($day)];
    }

    /**
     * Frozen rows joined to products that are still public, rank 1..LIMIT (the frozen rank, at most 100 rows). The
     * frozen rank and vote count win over the live product values. A product hidden since the freeze is missing and
     * its rank is not reused, so a frozen page can have gaps (ranks 2, 3, 5 ...), while the not-yet-frozen page
     * numbers its products densely from 1. A product appended by a late freeze simply has the next rank.
     *
     * @return list<array{product: Product, rank: int}>
     */
    private function frozen(string $date): array
    {
        $products = Product::visible()
            ->whereMakerAvailable()
            ->joinSub(
                DB::table('daily_rankings')->select('product_id', 'rank as frozen_rank', 'votes_count as frozen_votes_count')
                    ->where('launch_date', $date)->where('rank', '<=', TodayRanking::LIMIT),
                'frozen',
                'frozen.product_id', '=', 'products.id',
            )
            ->select('products.*', 'frozen.frozen_rank', 'frozen.frozen_votes_count')
            ->with(['maker.links', 'topics'])
            ->orderBy('frozen.frozen_rank')
            ->get();

        return array_values($products->map(function (Product $product): array {
            $rank = (int) $product->getAttribute('frozen_rank');
            $product->forceFill(['votes_count' => (int) $product->getAttribute('frozen_votes_count')]);
            $product->offsetUnset('frozen_rank');
            $product->offsetUnset('frozen_votes_count');

            return ['product' => $product, 'rank' => $rank];
        })->all());
    }

    /**
     * The order the freeze will write, numbered densely. Bounded work: only ids and vote counts of the day's public
     * products are read, the order is computed over them (the bucket query is chunked), and only the page's
     * products are loaded as models with their relations.
     *
     * @return list<array{product: Product, rank: int}>
     */
    private function computed(CarbonImmutable $day): array
    {
        $rows = Product::visible()
            ->whereMakerAvailable()
            ->where('launch_date', $day->toDateString())
            ->toBase()
            ->get(['id', 'votes_count']);

        $ordered = $this->order->handle(
            array_values($rows->map(fn (object $row): array => ['id' => (string) $row->id, 'votes_count' => (int) $row->votes_count])->all()),
            $day,
            HotScore::fromSettings($this->settings),
        );

        $page = array_slice($ordered, 0, TodayRanking::LIMIT);

        $products = Product::visible()
            ->whereMakerAvailable()
            ->whereIn('id', array_column($page, 'id'))
            ->with(['maker.links', 'topics'])
            ->get()
            ->keyBy(fn (Product $product): string => (string) $product->getKey());

        $items = [];
        foreach ($page as $entry) {
            $product = $products->get($entry['id']);
            if ($product !== null) {
                $items[] = ['product' => $product, 'rank' => count($items) + 1];
            }
        }

        return $items;
    }
}
