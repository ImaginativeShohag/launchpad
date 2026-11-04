<?php

namespace App\Actions\Vote;

use App\Enums\VoteStatus;
use App\Events\VoteVoided;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use App\Support\Audit;
use App\Support\CleanText;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoidVote
{
    private const MAX_REASON = 255;

    /**
     * Voids one vote: status `voided`, and when it was still active the product counter and the bucket of its ORIGINAL
     * `voted_at` hour go back by one. Idempotent: an already voided vote returns false and changes nothing.
     *
     * Lock order (global): the PRODUCT row first, then the vote row. No user row is ever locked here (the audit FK
     * takes an implicit key share on the actor, after the product row), so this can run while a member is being deleted.
     * The product id is read unlocked only to know which row to lock, and the vote is re-read under both locks.
     *
     * @param  string  $reason  plain text kept on the vote row; it is NOT written to the audit meta
     * @return bool true when this call voided the vote
     *
     * @throws ModelNotFoundException for an unknown vote
     * @throws ProductRuleViolation 409 `day_frozen` when the product's launch day is before today (UTC)
     */
    public function handle(string $voteId, string $reason, ?User $actor = null): bool
    {
        if (! Str::isUlid($voteId)) {
            throw (new ModelNotFoundException)->setModel(Vote::class);
        }

        return DB::transaction(function () use ($voteId, $reason, $actor): bool {
            $productId = DB::table('votes')->where('id', $voteId)->value('product_id');

            if (! is_string($productId)) {
                throw (new ModelNotFoundException)->setModel(Vote::class);
            }

            // Soft-deleted products keep their votes; the lock must still find the row.
            $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $vote = Vote::query()->whereKey($voteId)->lockForUpdate()->firstOrFail();
            // After the lock wait: a void that queued across 00:00 UTC must see the new day.
            $now = Carbon::now('UTC')->startOfSecond();

            if ($vote->status === VoteStatus::Voided) {
                return false;
            }

            if ($product->launch_date !== null && $product->launch_date->toDateString() < $now->toDateString()) {
                throw ProductRuleViolation::dayFrozen();
            }

            $wasActive = $vote->removed_at === null && $vote->status->counts();

            $vote->forceFill([
                'status' => VoteStatus::Voided,
                'voided_at' => $now,
                'voided_reason' => mb_substr((string) CleanText::clean($reason), 0, self::MAX_REASON),
            ])->save();

            if ($wasActive) {
                // Relative update: safe even for a writer that does not hold this lock.
                DB::update('UPDATE products SET votes_count = greatest(votes_count - 1, 0) WHERE id = ?', [$productId]);
                HourlyBuckets::add($productId, $vote->voted_at, -1);
            }

            Audit::record('vote.voided', $actor, $vote, ['vote_id' => $voteId, 'product_id' => $productId]);
            VoteVoided::dispatch($productId, (string) $vote->user_id);

            return true;
        });
    }
}
