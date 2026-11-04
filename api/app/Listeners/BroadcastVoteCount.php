<?php

namespace App\Listeners;

use App\Events\VoteCast;
use App\Events\VoteCountUpdated;
use App\Events\VoteRemoved;
use App\Events\VoteVoided;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Tells the live page that a product's count moved. Queued, so a vote never waits for the websocket server, and a
 * broadcast failure is reported and swallowed: it must never fail or undo a vote. The count is read again here (not
 * taken from the event), so a late delivery still carries the current number.
 *
 * The after-commit guarantee belongs to the EVENTS (VoteEvent is ShouldDispatchAfterCommit): the listener is only invoked
 * once the vote's transaction has committed, so a queued job never reads a count that is later rolled back.
 *
 * Deliberately NOT named `handle`: Laravel's listener discovery would register such a method on top of the explicit
 * Event::listen() calls in AppServiceProvider and every vote would broadcast twice.
 *
 * A product that is not publicly visible (taken down, soft-deleted, not live, or by a deleted maker) broadcasts nothing:
 * the channel is public, so a hidden product's count must not leak through it.
 */
final class BroadcastVoteCount implements ShouldQueue
{
    public int $tries = 1;

    public function broadcast(VoteCast|VoteRemoved|VoteVoided $event): void
    {
        try {
            // In a savepoint, so a failed read cannot poison a surrounding Postgres transaction (SQLSTATE 25P02).
            $count = DB::transaction(fn () => Product::query()->visible()->whereMakerAvailable()->whereKey($event->productId)->value('votes_count'));

            if ($count === null) {
                return;
            }

            VoteCountUpdated::dispatch($event->productId, (int) $count);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
