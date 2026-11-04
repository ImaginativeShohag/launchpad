<?php

use App\Actions\AntiCheat\ScoreUsers;
use App\Enums\RiskSignalKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// No row is created before the commit below: the test commits its own fixtures and removes exactly those, by id.

it('skips a member another session holds locked and rescores it on the next run', function () {
    // Given two committed members with signals, one of them locked by a second session
    $usersBefore = DB::table('users')->count();
    DB::commit();
    $ids = [];
    try {
        $members = [User::factory()->create(), User::factory()->create()];
        $ids = array_map(fn ($u) => $u->id, $members);
        foreach ($members as $member) {
            antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 0, null, ['dedupe_key' => 'lock:'.$member->id]);
        }
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM users WHERE id = ? FOR UPDATE', [$members[0]->id]);
        DB::statement("SET lock_timeout = '2s'");

        // When the scoring runs while the lock is held
        try {
            $started = microtime(true);
            $changed = app(ScoreUsers::class)->handle($ids, antiCheatNow());
            $waited = microtime(true) - $started;
        } finally {
            $other->rollBack();
        }

        // Then it did not wait, scored the free member only, and the next run catches the other
        expect($waited)->toBeLessThan(1.5)->and($changed)->toBe(1);
        expect(antiCheatScoreOf($members[0]))->toBe(0)->and(antiCheatScoreOf($members[1]))->toBe(40);
        expect(app(ScoreUsers::class)->handle($ids, antiCheatNow()))->toBe(1)->and(antiCheatScoreOf($members[0]))->toBe(40);
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('risk_signals')->whereIn('user_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();
    }

    expect(DB::table('users')->count())->toBe($usersBefore);
});
