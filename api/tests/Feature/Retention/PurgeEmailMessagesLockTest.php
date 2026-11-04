<?php

use App\Actions\Retention\PurgeEmailMessages;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

// No user is created before the commit below: the test commits its own fixtures and removes exactly those, by id.
beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

it('skips a row another session holds locked and catches it next run, and leaves no user behind', function () {
    // Given the number of members in the testing database, then committed old rows with one held by a second session
    $usersBefore = DB::table('users')->count();
    DB::commit();
    $user = null;
    $ids = [];
    try {
        $user = User::factory()->create();
        $ids = collect(range(1, 3))->map(fn (int $i) => retentionMail($user->id, 'sent', retentionNow()->subDays(100 + $i)))->all();
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM email_messages WHERE id = ? FOR UPDATE', [$ids[0]]);
        DB::statement("SET lock_timeout = '2s'");

        // When the purge runs while the lock is held
        try {
            $purged = retentionPurger(PurgeEmailMessages::class, 10, 1)->purge(retentionNow());
        } finally {
            $other->rollBack();
        }

        // Then it took the two free rows without waiting, and the next run takes the third
        expect($purged)->toBe(2)->and(DB::table('email_messages')->whereIn('id', $ids)->pluck('id')->all())->toBe([$ids[0]]);
        expect(retentionPurger(PurgeEmailMessages::class, 10, 1)->purge(retentionNow()))->toBe(1);
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('email_messages')->whereIn('id', $ids)->delete();
        if ($user !== null) {
            DB::table('users')->where('id', $user->id)->delete();
        }
    }

    // And the committed member is gone: the table holds as many members as before
    expect(DB::table('users')->count())->toBe($usersBefore);
});
