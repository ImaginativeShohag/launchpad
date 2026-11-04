<?php

namespace App\Actions\Ranking;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/** The top launches of a period (optionally of one topic): GET /leaderboards. */
class Leaderboard
{
    public const LIMIT = 50;

    public const PERIODS = ['week', 'month', 'all'];

    /**
     * The best visible launches of the period ending on the UTC day of $now: votes, then newest launch day, then id.
     * The list card needs the maker (with links) and topics only, so the cost does not grow with the list.
     *
     * @return Collection<int, Product>
     */
    public function products(string $period, ?string $topic, CarbonImmutable $now, int $limit = self::LIMIT): Collection
    {
        return $this->query($period, $topic, $now)->with(['maker.links', 'topics'])->limit($limit)->get();
    }

    /**
     * The same order as products(), reduced to id and votes_count (no relations).
     *
     * `$through` (a UTC date, Y-m-d) ends the window on that day instead of today: the weekly digest ranks the week that
     * just ended, and on Monday morning today's launches must not count. It is a second upper bound for a PAST window, not
     * a copy of visible()'s (which stays "today" and still applies). Null keeps every other caller unchanged.
     *
     * @return Collection<int, Product>
     */
    public function ranking(string $period, ?string $topic, CarbonImmutable $now, int $limit = self::LIMIT, ?string $through = null): Collection
    {
        return $this->query($period, $topic, $now, $through)->limit($limit)->get(['id', 'votes_count']);
    }

    /** @return Builder<Product> */
    private function query(string $period, ?string $topic, CarbonImmutable $now, ?string $through = null): Builder
    {
        $today = $now->utc()->startOfDay();
        $from = match ($period) {
            'week' => $today->subDays(6)->toDateString(),
            'month' => $today->subDays(29)->toDateString(),
            'all' => null,
            default => throw new InvalidArgumentException("Unknown leaderboard period [{$period}]."),
        };

        $query = Product::visible()
            ->whereMakerAvailable()
            // The upper bound (launch_date <= today) is visible()'s.
            ->when($from !== null, fn (Builder $query) => $query->where('launch_date', '>=', $from))
            ->when($through !== null, fn (Builder $query) => $query->where('launch_date', '<=', $through))
            ->orderByDesc('votes_count')
            ->orderByDesc('launch_date')
            ->orderBy('id');

        if ($topic !== null) {
            $query->hasTopicSlug($topic);
        }

        return $query;
    }
}
