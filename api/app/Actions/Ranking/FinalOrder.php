<?php

namespace App\Actions\Ranking;

use Carbon\CarbonImmutable;

/**
 * The permanent order of a finished launch day: final score (hour 23 of its buckets), then votes, then id. The one
 * place both the freeze and the not-yet-frozen day page get their order from.
 */
class FinalOrder
{
    public function __construct(private readonly BucketReader $buckets) {}

    /**
     * @param  list<array{id: string, votes_count: int}>  $products
     * @return list<array{id: string, votes_count: int, score: float}> best first
     */
    public function handle(array $products, CarbonImmutable $day, HotScore $hotScore): array
    {
        $buckets = $this->buckets->forDay(array_column($products, 'id'), $day);

        $scored = array_map(fn (array $product): array => [
            'id' => $product['id'],
            'votes_count' => $product['votes_count'],
            'score' => $hotScore->score($buckets[$product['id']] ?? [], 23),
        ], $products);

        // strcmp, not <=>: a ULID made of digits and one E would compare as a number.
        usort($scored, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($b['votes_count'] <=> $a['votes_count']) ?: strcmp($a['id'], $b['id']));

        return $scored;
    }
}
