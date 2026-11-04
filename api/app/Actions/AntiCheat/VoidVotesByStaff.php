<?php

namespace App\Actions\AntiCheat;

use App\Actions\AntiCheat\Concerns\RequiresActiveStaff;
use App\Actions\Vote\VoidVote;
use App\Exceptions\ProductRuleViolation;
use App\Models\User;
use App\Models\Vote;
use App\Support\Audit;
use App\Support\CleanText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * An admin voids one vote, or all of one member's votes on launch days that are not frozen. `VoidVote` is the mechanism
 * (counter, hourly bucket, `vote.voided` audit row, `VoteVoided` event); it checks no role, so this class reads the actor's
 * role first. Frozen days are final and never touched.
 *
 * Lock order: every vote is its own transaction that starts with a plain role read, then `VoidVote` takes the product row,
 * then the vote row (the audit insert's key share on the actor comes last). The member loop is never wrapped in one outer
 * transaction and never holds a user or signal row, and it walks the votes in product id order, the global order.
 */
class VoidVotesByStaff
{
    use RequiresActiveStaff;

    /** Votes processed per call; the 101st candidate only tells the caller there is more. */
    public const LIMIT = 100;

    public function __construct(private readonly VoidVote $void) {}

    /**
     * @param  string  $reason  plain text, kept on the vote row; it is not written to the `vote.voided` audit meta
     * @return bool true when this call voided the vote, false when it already was
     *
     * @throws AuthorizationException when the actor is no longer an active admin
     * @throws ModelNotFoundException for an unknown vote
     * @throws ProductRuleViolation 409 `day_frozen`
     */
    public function vote(User $actor, string $voteId, string $reason): bool
    {
        // The role first: a stale or non-admin actor learns nothing about ids.
        $this->assertAdmin($actor);

        if (! Str::isUlid($voteId)) {
            throw (new ModelNotFoundException)->setModel(Vote::class);
        }

        // One transaction: the plain role read comes first, VoidVote's own transaction becomes a savepoint, so the
        // VoteVoided event still fires after this outer commit.
        return DB::transaction(function () use ($actor, $voteId, $reason): bool {
            $this->assertAdmin($actor);

            return $this->void->handle($voteId, $reason, $actor);
        });
    }

    /**
     * Voids up to 100 of the member's active votes on unfrozen days (optionally only on one product), oldest product first.
     * One failing vote never stops the others. Writes one summary audit row when it voided or failed anything.
     *
     * Callers loop until `hasMore` is false OR `voided` is 0: if 100 or more votes keep failing, `hasMore` stays true while a
     * call voids nothing, so a caller that only watches `hasMore` would never stop.
     *
     * @throws AuthorizationException when the actor is no longer an active admin (before anything is looked up)
     * @throws ModelNotFoundException for an unknown member (a deleted member is allowed: their votes can still be voided)
     */
    public function forMember(User $actor, string $memberId, string $reason, ?string $productId = null): VoidVotesResult
    {
        $this->assertAdmin($actor);

        $member = Str::isUlid($memberId) ? User::query()->find($memberId) : null;

        if ($member === null) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        if ($productId !== null && ! Str::isUlid($productId)) {
            return new VoidVotesResult(0, 0, 0, false); // no such product can exist
        }

        $today = Carbon::now('UTC')->toDateString();
        $filter = $productId === null ? '' : ' AND v.product_id = ?';
        $extra = $productId === null ? [] : [$productId];

        $candidates = array_map(fn (object $row) => (string) $row->id, Sql::select(
            'SELECT v.id FROM votes v JOIN products p ON p.id = v.product_id'
            .' WHERE v.user_id = ? AND '.Sql::activeVote('v').' AND p.launch_date >= ?'.$filter
            .' ORDER BY v.product_id, v.id LIMIT '.(self::LIMIT + 1),
            [$memberId, $today, ...$extra],
        ));
        $hasMore = count($candidates) > self::LIMIT;

        $voided = 0;
        $failed = 0;
        // Informational: the member's active votes on days that were already frozen when the call started (never processed). Counted before the loop, so a day that freezes
        // meanwhile is counted once, by the loop.
        $skippedFrozen = (int) Sql::select(
            'SELECT COUNT(*) AS n FROM votes v JOIN products p ON p.id = v.product_id WHERE v.user_id = ? AND '.Sql::activeVote('v').' AND p.launch_date < ?'.$filter,
            [$memberId, $today, ...$extra],
        )[0]->n;

        try {
            foreach (array_slice($candidates, 0, self::LIMIT) as $voteId) {
                try {
                    // Its own transaction (no outer one): a failed statement cannot poison the others.
                    if ($this->vote($actor, $voteId, $reason)) {
                        $voided++;
                    }
                } catch (AuthorizationException $e) {
                    throw $e; // the actor lost the role mid-run: stop at once
                } catch (Throwable $e) {
                    if ($e instanceof ProductRuleViolation && $e->errorCode === 'day_frozen') {
                        $skippedFrozen++; // the day froze while the call was running

                        continue;
                    }

                    report($e);
                    $failed++;
                }
            }
        } finally {
            // Written even when the loop stops early (the actor lost the role mid-run), and never allowed to turn a call
            // that did its work into an error.
            if ($voided + $failed > 0) {
                try {
                    DB::transaction(fn () => Audit::record('member.votes_voided', $actor, $member, [
                        'user_id' => $memberId, 'product_id' => $productId, 'voided' => $voided, 'skipped_frozen' => $skippedFrozen,
                        'failed' => $failed, 'reason' => mb_substr((string) CleanText::clean($reason), 0, 255),
                    ]));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return new VoidVotesResult($voided, $skippedFrozen, $failed, $hasMore);
    }
}
