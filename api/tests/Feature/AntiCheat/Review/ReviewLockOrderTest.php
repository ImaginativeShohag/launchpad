<?php

use App\Actions\AntiCheat\ReviewRiskSignal;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// No row is created before the commit below: the test commits its own fixtures and removes exactly those, by id.
// The refused review is rolled back, so it leaves no audit row (audit_logs is append-only and cannot be cleaned).

it('waits for the subject user row before it recomputes the score, and gives up cleanly on a lock timeout', function () {
    // Given a committed reviewer, a member with an open signal, and the member row locked by a second session (a scoring pass or another review)
    $usersBefore = DB::table('users')->count();
    DB::commit();
    $ids = [];
    try {
        $reviewer = User::factory()->role(Role::Reviewer)->create();
        $member = User::factory()->create();
        $ids = [$reviewer->id, $member->id];
        $signal = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 0, null, ['dedupe_key' => 'review-lock:'.$member->id]);
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE', [$member->id]);
        DB::statement("SET lock_timeout = '1s'");

        // When the review runs while the lock is held
        try {
            $started = microtime(true);
            $error = null;
            try {
                app(ReviewRiskSignal::class)->handle($reviewer, $signal->id, RiskOutcome::Confirmed, null);
            } catch (QueryException $e) {
                $error = $e;
            }
            $waited = microtime(true) - $started;
        } finally {
            $other->rollBack();
        }

        // Then it waited for the lock (the user lock is taken before the score is read), timed out with 55P03, and rolled everything back
        expect($error)->toBeInstanceOf(QueryException::class)->and($error->errorInfo[0])->toBe('55P03');
        expect($waited)->toBeGreaterThan(0.8)->toBeLessThan(3.0);
        expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull()->and(DB::selectOne('select 1 as one')->one)->toBe(1);
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('risk_signals')->whereIn('user_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();
    }

    expect(DB::table('users')->count())->toBe($usersBefore);
});
