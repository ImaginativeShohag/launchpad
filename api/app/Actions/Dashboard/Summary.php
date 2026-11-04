<?php

namespace App\Actions\Dashboard;

use App\Actions\Views\ReferrerSource;
use App\Enums\DashboardPhase;
use App\Enums\ProductStatus;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The KPI tiles of the dashboard. Views come from the hourly buckets, votes and comments from the product's own counters
 * (the same numbers the feed uses), the ranks from RankHistory. Read-only; takes no lock.
 */
final class Summary
{
    public function __construct(private readonly RankHistory $rank) {}

    /**
     * @return array{product_id: string, name: string, slug: string, status: string, phase: string, launch_date: ?string, taken_down: bool, views: int, votes: int, comments: int, conversion: ?float, current_rank: ?int, best_rank: ?int, final_rank: ?int, top_source: ?array{source: string, category: string, visits: int}, timeline_available: bool, as_of: string}
     */
    public function handle(Product $product, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $phase = DashboardPhase::of($product, $now);

        $views = (int) (DB::selectOne('SELECT COALESCE(SUM(views), 0) AS views FROM product_hourly_stats WHERE product_id = ?', [$product->getKey()])->views ?? 0);
        $top = DB::selectOne(
            'SELECT source, SUM(visits)::int AS visits FROM product_referrers_daily WHERE product_id = ? GROUP BY source ORDER BY 2 DESC, source COLLATE "C" LIMIT 1',
            [$product->getKey()],
        );
        $facts = $this->rank->facts($product, $now);
        $votes = $product->votes_count;

        return [
            'product_id' => (string) $product->getKey(),
            'name' => $product->name,
            'slug' => $product->slug,
            'status' => $product->status->value,
            'phase' => $phase->value,
            'launch_date' => $product->launch_date?->toDateString(),
            'taken_down' => $product->status === ProductStatus::TakenDown,
            'views' => $views,
            'votes' => $votes,
            'comments' => $product->comments_count,
            'conversion' => $views === 0 ? null : round($votes / $views, 4),
            'current_rank' => $facts['current_rank'],
            'best_rank' => $facts['best_rank'],
            'final_rank' => $facts['final_rank'],
            'top_source' => $top === null ? null : [
                'source' => (string) $top->source,
                'category' => ReferrerSource::category((string) $top->source),
                'visits' => (int) $top->visits,
            ],
            'timeline_available' => $facts['timeline_available'],
            'as_of' => $now->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
