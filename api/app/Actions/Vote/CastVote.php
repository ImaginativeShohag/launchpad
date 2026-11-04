<?php

namespace App\Actions\Vote;

use App\Actions\Vote\Concerns\LocksVotableProduct;
use App\Enums\VoteStatus;
use App\Events\VoteCast;
use App\Exceptions\ProductRuleViolation;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CastVote
{
    use LocksVotableProduct;

    /**
     * Casts, or re-casts after a withdrawal. Idempotent: an active vote changes nothing and fires nothing. A voided
     * vote stays voided and the answer does not reveal it.
     *
     * @return array{voted: bool, votes_count: int}
     */
    public function handle(User $voter, string $productId, VoteSignals $signals): array
    {
        return DB::transaction(function () use ($voter, $productId, $signals): array {
            $product = $this->lockVotable($voter, $productId);
            $count = $product->votes_count;

            $vote = Vote::query()->where('user_id', $voter->getKey())->where('product_id', $productId)->lockForUpdate()->first();
            // Whole seconds: `voted_at` is timestamptz(0), so the bucket must be the hour of the stored value.
            $now = now()->startOfSecond();

            if ($product->launch_date?->toDateString() !== $now->copy()->utc()->toDateString()) {
                throw ProductRuleViolation::votingClosed();
            }

            if ($vote === null) {
                try {
                    // A savepoint: a unique violation must not poison the outer transaction.
                    $vote = DB::transaction(fn (): Vote => $this->insert($voter, $productId, $signals, $now));
                } catch (UniqueConstraintViolationException) {
                    // Cannot happen under the product lock; if it ever does, treat the other row as the existing one.
                    $vote = Vote::query()->where('user_id', $voter->getKey())->where('product_id', $productId)->lockForUpdate()->firstOrFail();

                    return $this->answer($vote, $count);
                }
            } elseif ($vote->status === VoteStatus::Voided || $vote->removed_at === null) {
                return $this->answer($vote, $count);
            } else {
                $vote->forceFill([
                    'removed_at' => null,
                    'voted_at' => $now,
                    'toggle_count' => $vote->toggle_count + 1,
                    'ip_hash' => $signals->ipHash,
                    'device_hash' => $signals->deviceHash,
                    'user_agent' => $signals->userAgent,
                ])->save();
            }

            // Relative update: safe even for a writer that does not hold this lock (the void job).
            $count = (int) DB::selectOne('UPDATE products SET votes_count = votes_count + 1 WHERE id = ? RETURNING votes_count', [$productId])->votes_count;
            HourlyBuckets::add($productId, $now, 1);
            VoteCast::dispatch($productId, (string) $voter->getKey());

            return ['voted' => true, 'votes_count' => $count];
        });
    }

    /** @return array{voted: bool, votes_count: int} */
    private function answer(Vote $vote, int $count): array
    {
        return ['voted' => $vote->status !== VoteStatus::Voided && $vote->removed_at === null, 'votes_count' => $count];
    }

    private function insert(User $voter, string $productId, VoteSignals $signals, CarbonInterface $now): Vote
    {
        $vote = new Vote;
        $vote->forceFill([
            'user_id' => $voter->getKey(),
            'product_id' => $productId,
            'status' => VoteStatus::Counted,
            'voted_at' => $now,
            'removed_at' => null,
            'toggle_count' => 0,
            'ip_hash' => $signals->ipHash,
            'device_hash' => $signals->deviceHash,
            'user_agent' => $signals->userAgent,
            'voided_at' => null,
            'voided_reason' => null,
        ])->save();

        return $vote;
    }
}
