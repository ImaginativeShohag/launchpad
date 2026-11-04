<?php

namespace App\Actions\Dashboard;

use App\Actions\Ranking\TodayRanking;
use App\Enums\DashboardPhase;
use App\Enums\ProductStatus;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where a product stood: the launch day's 10-minute marks (`rank_snapshots`, top 500, primary-key range), the best of them,
 * the live position (the feed's own query) and the frozen final rank (`daily_rankings`, primary key). Nothing is inverted,
 * smoothed or resampled: a mark outside the top 500 simply has no point. Read-only; takes no lock.
 */
final class RankHistory
{
    public const OUTSIDE_TOP = 500;

    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly TodayRanking $ranking, private readonly Settings $settings) {}

    /**
     * @return array{launch_date: ?string, phase: string, frozen: bool, final_rank: ?int, current_rank: ?int, best_rank: ?int, best_rank_at: ?string, timeline_available: bool, interval_minutes: int, points: list<array{t: string, rank: int, score: float}>, outside_top: int}
     */
    public function handle(Product $product, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $facts = $this->facts($product, $now);
        $points = [];

        if ($facts['timeline_available'] && $facts['launch'] !== null) {
            [$start, $end] = $facts['range'];
            $rows = DB::select(
                'SELECT captured_at, rank, score FROM rank_snapshots WHERE product_id = ? AND captured_at >= ? AND captured_at < ? ORDER BY captured_at',
                [$product->getKey(), $start, $end],
            );
            foreach ($rows as $row) {
                $points[] = ['t' => CarbonImmutable::parse($row->captured_at)->utc()->format(self::FORMAT), 'rank' => (int) $row->rank, 'score' => (float) $row->score];
            }
        }

        return [
            'launch_date' => $facts['launch'],
            'phase' => DashboardPhase::of($product, $now)->value,
            'frozen' => $facts['frozen'],
            'final_rank' => $facts['final_rank'],
            'current_rank' => $facts['current_rank'],
            'best_rank' => $facts['best_rank'],
            'best_rank_at' => $facts['best_rank_at'],
            'timeline_available' => $facts['timeline_available'],
            'interval_minutes' => 10,
            'points' => $points,
            'outside_top' => self::OUTSIDE_TOP,
        ];
    }

    /**
     * Everything except the points, in three small primary-key reads at most (the summary uses this alone).
     *
     * @return array{launch: ?string, range: array{0: string, 1: string}, frozen: bool, final_rank: ?int, current_rank: ?int, best_rank: ?int, best_rank_at: ?string, timeline_available: bool}
     */
    public function facts(Product $product, CarbonImmutable $now): array
    {
        $phase = DashboardPhase::of($product, $now);
        $launch = $product->launch_date?->toDateString();
        $none = ['launch' => $launch, 'range' => ['', ''], 'frozen' => false, 'final_rank' => null, 'current_rank' => null, 'best_rank' => null, 'best_rank_at' => null, 'timeline_available' => false];

        if ($phase === DashboardPhase::BeforeLaunch || $launch === null) {
            return $none;
        }

        $dayStart = CarbonImmutable::createFromFormat('!Y-m-d', $launch, 'UTC');
        if ($dayStart === null) {
            return $none;
        }

        $range = [$dayStart->format('Y-m-d H:i:sP'), $dayStart->addDay()->format('Y-m-d H:i:sP')];
        $final = DB::selectOne('SELECT rank FROM daily_rankings WHERE launch_date = ? AND product_id = ?', [$launch, $product->getKey()]);

        // Some marks may still exist while the launch day's end is inside the retention window.
        $days = $this->settings->days(SettingKey::RetentionRankSnapshotsDays);
        $available = $dayStart->addDay()->greaterThan($now->subDays($days));

        $best = null;
        if ($available) {
            $best = DB::selectOne(
                'SELECT rank, captured_at FROM rank_snapshots WHERE product_id = ? AND captured_at >= ? AND captured_at < ? ORDER BY rank, captured_at LIMIT 1',
                [$product->getKey(), $range[0], $range[1]],
            );
        }

        // Defence in depth: positionOf already answers null outside the launch day and for a product that is not live.
        $current = $phase === DashboardPhase::LaunchDay && $product->status === ProductStatus::Live
            ? $this->ranking->positionOf($now, $product)
            : null;

        return [
            'launch' => $launch,
            'range' => $range,
            'frozen' => $final !== null,
            'final_rank' => $final === null ? null : (int) $final->rank,
            'current_rank' => $current,
            'best_rank' => $best === null ? null : (int) $best->rank,
            'best_rank_at' => $best === null ? null : CarbonImmutable::parse($best->captured_at)->utc()->format(self::FORMAT),
            'timeline_available' => $available,
        ];
    }
}
