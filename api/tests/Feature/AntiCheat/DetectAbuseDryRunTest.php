<?php

use App\Actions\AntiCheat\Rules\ProductBurstRule;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Three accounts on one device that voted for the same four products: R1 fires, and R8 only after R1 wrote. */
function antiCheatCoordinatedRing(): void
{
    antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 100]);
    $users = User::factory()->count(3)->create(['created_at' => antiCheatNow()->subDays(10)]);
    for ($i = 0; $i < 4; $i++) {
        $product = antiCheatLiveProduct();
        foreach ($users as $user) {
            antiCheatVote($user, $product, ['device_hash' => antiCheatHash('device-ring'), 'ip_hash' => antiCheatHash('ip-'.$user->id)]);
        }
    }
}

it('prints real counts, including the coordinated rule, and leaves no row behind', function () {
    // Given a ring that only the coordinated rule can link after the shared-device rule wrote
    antiCheatFreeze();
    antiCheatCoordinatedRing();
    $level = DB::transactionLevel();

    // When the job runs as a dry run
    $result = antiCheatDetect(['dryRun' => true]);

    // Then R8 saw the rows of R1 inside the run, nothing persisted, and the connection is where it started
    expect($result->signals)->toBe(['shared_device' => 12, 'coordinated_votes' => 3])->and($result->failedChunks)->toBe(0);
    expect(RiskSignal::query()->count())->toBe(0)->and(DB::transactionLevel())->toBe($level);
});

it('rolls back and restores the transaction level even when the run throws', function () {
    // Given a ring and a settings read that explodes after the transaction started
    antiCheatFreeze();
    antiCheatCoordinatedRing();
    $level = DB::transactionLevel();
    app()->bind(ProductBurstRule::class, fn () => throw new RuntimeException('cannot build the rule'));

    // When the dry run fails outside a chunk
    // Then the exception propagates and the transaction was rolled back
    expect(fn () => antiCheatDetect(['dryRun' => true]))->toThrow(RuntimeException::class);
    expect(DB::transactionLevel())->toBe($level)->and(RiskSignal::query()->count())->toBe(0);
});

it('sets a local lock timeout only for a dry run, before anything else is read', function () {
    // Given a ring
    antiCheatFreeze();
    antiCheatCoordinatedRing();

    // When the job runs as a dry run and as a real run under the query recorder
    $dry = antiCheatQueries(fn () => antiCheatDetect(['dryRun' => true]));
    $real = antiCheatQueries(fn () => antiCheatDetect());

    // Then only the dry run sets the local timeout, and it is the first statement after the transaction starts
    $index = array_keys(array_filter($dry, fn (string $sql) => str_contains($sql, "set local lock_timeout = '10s'")));
    expect($index)->toHaveCount(1)->and($index[0])->toBeLessThan(3);
    expect(collect($real)->contains(fn (string $sql) => str_contains($sql, 'set local lock_timeout')))->toBeFalse();
});

it('writes for real when it is not a dry run, with the same counts', function () {
    // Given the same ring
    antiCheatFreeze();
    antiCheatCoordinatedRing();

    // When the job runs for real
    $result = antiCheatDetect();

    // Then the same counts are stored
    expect($result->signals)->toBe(['shared_device' => 12, 'coordinated_votes' => 3])->and(RiskSignal::query()->count())->toBe(15);
});
