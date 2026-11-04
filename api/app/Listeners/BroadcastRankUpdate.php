<?php

namespace App\Listeners;

use App\Actions\Ranking\TodayRanking;
use App\Events\RankingUpdated;
use App\Events\RankUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Tells the live page that today's order moved. Queued (one pending job per day, see uniqueId), because the scheduler
 * dispatches RankingUpdated synchronously from the recompute command; a failure (also a down Reverb: the event is published inside this job) is reported and
 * swallowed here, it must never fail the recompute.
 *
 * The order is read again here (not taken from the event), so a late delivery carries the CURRENT top 100. Only
 * publicly visible products are ranked (TodayRanking), so the public channel never reveals a hidden one.
 *
 * Nothing is broadcast when the event's day is no longer today (UTC; a stale job that ran after midnight, the new
 * day announces itself on its own recompute) or when the day has no visible product.
 *
 * Deliberately NOT named `handle`: Laravel's listener discovery would register such a method on top of the explicit
 * Event::listen() call in AppServiceProvider and every update would broadcast twice.
 */
final class BroadcastRankUpdate implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    public int $tries = 1;

    /** Below the queue's retry_after (90 s), so a hung publish is killed before the job is handed out again. */
    public int $timeout = 10;

    /** The pending-job lock expires on its own, so a killed worker cannot block the day for ever. */
    public int $uniqueFor = 30;

    /**
     * One pending job per day: the scheduler fires every minute, and after a worker outage the backlog would replay N
     * identical frames. The job reads the CURRENT order, so one is enough. The lock is released when the job starts,
     * so an event that arrives while it runs queues the next one.
     */
    public function uniqueId(RankingUpdated $event): string
    {
        return $event->day;
    }

    public function broadcast(RankingUpdated $event): void
    {
        try {
            $now = CarbonImmutable::now('UTC');

            if ($event->day !== $now->toDateString()) {
                return;
            }

            // In a savepoint, so a failed read cannot poison a surrounding Postgres transaction (SQLSTATE 25P02).
            $products = DB::transaction(fn () => app(TodayRanking::class)->ranking($now, TodayRanking::LIMIT));

            if ($products->isEmpty()) {
                return;
            }

            $items = [];
            foreach ($products->values() as $index => $product) {
                $items[] = ['product_id' => (string) $product->getKey(), 'rank' => $index + 1, 'votes_count' => (int) $product->votes_count];
            }

            RankUpdated::dispatch($event->day, $items);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
