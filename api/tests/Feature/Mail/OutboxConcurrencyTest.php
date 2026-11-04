<?php

use App\Actions\Email\ClaimDueEmails;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/MailFixtures.php';

/**
 * These tests need a second session that can see the first one's rows, so they COMMIT their fixtures (the suite's wrapping
 * transaction is committed first) and delete exactly the rows they created, by id, in a finally block.
 *
 * @param  callable(User, list<string>): void  $body  receives the member and the ids of the six committed rows
 */
function concurrencyRun(callable $body): void
{
    DB::commit();
    $user = null;
    $ids = [];
    try {
        $user = User::factory()->create();
        $ids = EmailMessage::factory()->forUser($user)->queued(CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC'))->count(6)->create()->pluck('id')->all();
        $body($user, $ids);
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('email_messages')->whereIn('id', $ids)->delete();
        if ($user !== null) {
            DB::table('users')->where('id', $user->id)->delete();
        }
    }
}

it('never shares a row between two sessions: the second claim skips what the first holds locked', function () {
    concurrencyRun(function (User $user, array $ids) {
        // Given a second session that holds three of the six committed rows locked
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $held = $other->select("SELECT id FROM email_messages WHERE status = 'queued' ORDER BY next_attempt_at, id LIMIT 3 FOR UPDATE SKIP LOCKED");
        expect($held)->toHaveCount(3);

        // And a short lock timeout, so a claim that waits on a locked row fails the test instead of hanging it
        DB::statement("SET lock_timeout = '2s'");

        // When the real claim runs while the other session still holds its three rows (it always lets go, even on a failure)
        try {
            $claims = app(ClaimDueEmails::class)->handle(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'), 50);
        } finally {
            $other->rollBack();
        }

        // Then it claimed exactly the other three, with no overlap
        expect($claims)->toHaveCount(3);
        expect(array_intersect(array_column($claims, 'id'), array_column($held, 'id')))->toBe([]);
        expect(count(array_unique([...array_column($claims, 'id'), ...array_column($held, 'id')])))->toBe(6);
    });
});

it('lets a second claim take the rest after the first committed', function () {
    concurrencyRun(function (User $user, array $ids) {
        // Given six committed due rows
        $now = CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');

        // When two claims of four run one after the other
        $first = app(ClaimDueEmails::class)->handle($now, 4);
        $second = app(ClaimDueEmails::class)->handle($now, 4);

        // Then they are disjoint and cover all six rows
        expect($first)->toHaveCount(4)->and($second)->toHaveCount(2);
        expect(array_intersect(array_column($first, 'id'), array_column($second, 'id')))->toBe([]);
    });
});
