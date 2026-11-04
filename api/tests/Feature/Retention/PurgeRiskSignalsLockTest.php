<?php

use App\Actions\Retention\PurgeRiskSignals;
use App\Enums\RiskSignalKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

// No user is created before the commit below: the test commits its own fixtures and removes exactly those, by id.
beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

it('skips a signal another session holds locked (a review in flight) and catches it next run, and leaves no user behind', function () {
    // Given committed due signals with one held by a second session
    $usersBefore = DB::table('users')->count();
    DB::commit();
    $user = null;
    $ids = [];
    try {
        $user = User::factory()->create();
        $ids = collect(range(1, 3))->map(fn (int $i) => antiCheatSignal($user, RiskSignalKind::SharedDevice, 40, 0, null, [
            'dedupe_key' => 'purge-lock:'.$user->id.':'.$i, 'detected_at' => retentionNow()->subDays(400 + $i),
        ])->id)->all();
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM risk_signals WHERE id = ? FOR UPDATE', [$ids[0]]);
        DB::statement("SET lock_timeout = '2s'");

        // When the purge runs while the lock is held
        try {
            $started = microtime(true);
            $purged = retentionPurger(PurgeRiskSignals::class, 10, 1)->purge(retentionNow());
            $waited = microtime(true) - $started;
        } finally {
            $other->rollBack();
        }

        // Then it took the free rows without waiting, and the next run takes the third
        expect($waited)->toBeLessThan(1.5)->and($purged)->toBe(2)->and(DB::table('risk_signals')->whereIn('id', $ids)->pluck('id')->all())->toBe([$ids[0]]);
        expect(retentionPurger(PurgeRiskSignals::class, 10, 1)->purge(retentionNow()))->toBe(1);
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('risk_signals')->whereIn('id', $ids)->delete();
        if ($user !== null) {
            DB::table('users')->where('id', $user->id)->delete();
        }
    }

    // And the committed member is gone
    expect(DB::table('users')->count())->toBe($usersBefore);
});
