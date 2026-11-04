<?php

namespace App\Actions\AntiCheat;

use App\Actions\Admin\SuspendUser;
use App\Actions\AntiCheat\Concerns\RequiresActiveStaff;
use App\Enums\RiskOutcome;
use App\Jobs\VoidMemberVotes;
use App\Models\RiskSignal;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The risk panel's "suspend": the community suspension (one implementation, one lock order), then the member's open
 * signals are confirmed, then, when staff asked, the vote clean-up job is queued. Three steps, three separate commits:
 *
 *  1. `SuspendUser` runs its own transaction (admin set, target user row). Already suspended is its silent no-op; an admin
 *     or oneself is its `403 cannot_suspend`; nothing else below happens in that case.
 *  2. The confirmation is a second transaction: the member's open signal rows (id order), then the user row for the score.
 *  3. The job is dispatched after both commits (voiding needs other makers' product rows, which must never be taken while
 *     a user or signal row is held).
 *
 * Steps 2 and 3 are retriable: calling again on a suspended member repeats them, which repairs a failed confirmation or a
 * dispatch that was lost (the response says `dispatch_failed`).
 */
class SuspendForAbuse
{
    use RequiresActiveStaff;

    public function __construct(private readonly SuspendUser $suspend, private readonly ScoreUsers $scores) {}

    /**
     * @return array{member: string, suspended_now: bool, signals_confirmed: int, void_votes: 'queued'|'dispatch_failed'|'not_requested'}
     *
     * @throws AuthorizationException when the actor is no longer an active admin
     * @throws ModelNotFoundException for an unknown, malformed or deleted member
     */
    public function handle(User $actor, string $memberId, string $reason, bool $voidVotes): array
    {
        $this->assertAdmin($actor);

        $target = Str::isUlid($memberId) ? User::query()->find($memberId) : null;

        if ($target === null || $target->isDeleted()) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        $wasSuspended = $target->isSuspended();
        $this->suspend->handle($actor, $target, $reason);

        $confirmed = $this->confirmOpenSignals($actor, $memberId);

        $queued = 'not_requested';

        if ($voidVotes) {
            $queued = VoidMemberVotes::dispatchSafely($memberId, VoidMemberVotes::REASON_SUSPENDED) ? 'queued' : 'dispatch_failed';
        }

        return ['member' => $memberId, 'suspended_now' => ! $wasSuspended, 'signals_confirmed' => $confirmed, 'void_votes' => $queued];
    }

    /** Confirms every open signal about the member (note `suspended`) and recomputes the score with the user row held. */
    private function confirmOpenSignals(User $actor, string $memberId): int
    {
        return DB::transaction(function () use ($actor, $memberId): int {
            $signals = RiskSignal::query()->where('user_id', $memberId)->whereNull('reviewed_at')->orderBy('id')->lockForUpdate()->get();

            if ($signals->isEmpty()) {
                return 0;
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();

            foreach ($signals as $signal) {
                $signal->forceFill([
                    'reviewed_by' => $actor->getKey(), 'reviewed_at' => $now, 'outcome' => RiskOutcome::Confirmed, 'review_note' => 'suspended',
                ])->save();

                Audit::record('risk_signal.reviewed', $actor, $signal, [
                    'signal_id' => $signal->getKey(), 'kind' => $signal->kind->value, 'user_id' => $signal->user_id,
                    'product_id' => $signal->product_id, 'before' => null, 'after' => RiskOutcome::Confirmed->value,
                ]);
            }

            // The user row is locked BEFORE the score is read (signal rows, then the user row: the global order).
            DB::selectOne('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE', [$memberId]);
            $this->scores->forLockedUser($memberId, $now);

            return $signals->count();
        });
    }
}
