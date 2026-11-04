<?php

namespace App\Actions\AntiCheat;

use App\Actions\AntiCheat\Concerns\RequiresActiveStaff;
use App\Enums\RiskOutcome;
use App\Enums\Role;
use App\Exceptions\AntiCheatRuleViolation;
use App\Models\RiskSignal;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ReviewRiskSignal
{
    use RequiresActiveStaff;

    public function __construct(private readonly ScoreUsers $scores) {}

    /**
     * Confirms or dismisses a signal and recomputes its subject's score in the same transaction. Re-reviewing with the
     * other outcome overwrites reviewer, time, outcome and note (audited with before and after); the same outcome again
     * changes nothing and writes no second audit row.
     *
     * Lock order: the signal row, then the subject's user row (`FOR NO KEY UPDATE`, taken BEFORE the score is read so two
     * reviews of one member's signals cannot both write a stale score), then the audit insert's key share on the actor.
     * No product row is touched. The role is read from the database first, with a plain read, never from the request's
     * model. Flags and unflags follow on the next detection run (at most five minutes).
     *
     * @return array{signal: RiskSignal, changed: bool}
     *
     * @throws AuthorizationException when the actor is no longer an active reviewer or admin
     * @throws ModelNotFoundException for an unknown signal
     * @throws AntiCheatRuleViolation `own_signal` when the signal is about the actor or their product, `admin_only_signal` when a reviewer reviews a signal about staff
     */
    public function handle(User $actor, string $signalId, RiskOutcome $outcome, ?string $note): array
    {
        return DB::transaction(function () use ($actor, $signalId, $outcome, $note): array {
            $this->assertStaff($actor);

            $signal = RiskSignal::query()->whereKey($signalId)->lockForUpdate()->firstOrFail();

            // Plain reads (no lock, no product or user row is held): who the signal is about, and who made its product.
            $makerId = $signal->product_id === null ? null : DB::table('products')->where('id', $signal->product_id)->value('maker_id');

            if ($signal->user_id === $actor->getKey() || $makerId === $actor->getKey()) {
                throw AntiCheatRuleViolation::ownSignal();
            }

            // Staff are judged by admins only: a reviewer cannot clear a colleague (or an admin).
            if ($signal->user_id !== null && $this->activeRole($actor) !== Role::Admin->value
                && in_array(DB::table('users')->where('id', $signal->user_id)->value('role'), [Role::Reviewer->value, Role::Admin->value], true)) {
                throw AntiCheatRuleViolation::adminOnlySignal();
            }

            if ($signal->outcome === $outcome) {
                return ['signal' => $signal, 'changed' => false];
            }

            $before = $signal->outcome;
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $signal->forceFill(['reviewed_by' => $actor->getKey(), 'reviewed_at' => $now, 'outcome' => $outcome, 'review_note' => $note])->save();

            if ($signal->user_id !== null) {
                // Deleted subjects are skipped: their score is irrelevant and ScoreUsers skips them too.
                $locked = DB::selectOne('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL FOR NO KEY UPDATE', [$signal->user_id]);

                if ($locked !== null) {
                    $this->scores->forLockedUser($signal->user_id, $now);
                }
            }

            Audit::record('risk_signal.reviewed', $actor, $signal, [
                'signal_id' => $signal->getKey(), 'kind' => $signal->kind->value, 'user_id' => $signal->user_id,
                'product_id' => $signal->product_id, 'before' => $before?->value, 'after' => $outcome->value,
            ]);

            return ['signal' => $signal, 'changed' => true];
        });
    }
}
