<?php

namespace App\Actions\Dashboard;

use App\Actions\Views\ReferrerSource;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Where the counted visits came from, summed over a day window (`product_referrers_daily`, primary-key range). The category of
 * each source is derived here from config/referrers.php, never stored. Read-only; takes no lock.
 */
final class Referrers
{
    public const TOP = 10;

    /** @return array{from: ?string, to: ?string, total: int, sources: list<array{source: string, category: string, visits: int, share: float}>, by_category: list<array{category: string, visits: int, share: float}>} */
    public function handle(Product $product, Window $window): array
    {
        $from = $window->from;
        $to = $window->to;
        $empty = ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'total' => 0, 'sources' => [], 'by_category' => []];

        if ($window->empty || $from === null || $to === null) {
            return $empty;
        }

        $rows = DB::select(
            'SELECT source, SUM(visits)::int AS visits FROM product_referrers_daily WHERE product_id = ? AND day BETWEEN ? AND ? GROUP BY source ORDER BY 2 DESC, source COLLATE "C"',
            [$product->getKey(), $from->toDateString(), $to->toDateString()],
        );

        /** @var array<string, int> $bySource */
        $bySource = [];
        $total = 0;
        foreach ($rows as $row) {
            $bySource[(string) $row->source] = (int) $row->visits;
            $total += (int) $row->visits;
        }

        if ($total === 0) {
            return $empty;
        }

        // One tie-break for everything (byte order), whatever collation the database sorts with.
        $bySource = self::sorted($bySource);

        // Top ten by visits then name; everything below folds into one `other` row, merged with a real `other` source.
        $top = array_slice($bySource, 0, self::TOP, true);
        $rest = array_sum(array_slice($bySource, self::TOP, null, true));
        if ($rest > 0) {
            $top['other'] = ($top['other'] ?? 0) + $rest;
        }
        $sources = self::sorted($top);

        $byCategory = [];
        foreach ($bySource as $source => $visits) {
            $category = ReferrerSource::category($source);
            $byCategory[$category] = ($byCategory[$category] ?? 0) + $visits;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => $total,
            'sources' => array_map(fn (string $source, int $visits) => [
                'source' => $source, 'category' => ReferrerSource::category($source), 'visits' => $visits, 'share' => round($visits / $total, 4),
            ], array_keys($sources), array_values($sources)),
            'by_category' => array_map(fn (string $category, int $visits) => [
                'category' => $category, 'visits' => $visits, 'share' => round($visits / $total, 4),
            ], array_keys(self::sorted($byCategory)), array_values(self::sorted($byCategory))),
        ];
    }

    /**
     * Visits descending, then name ascending (byte order, as the SQL ordering of ASCII keys).
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        uksort($counts, fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));

        return $counts;
    }
}
