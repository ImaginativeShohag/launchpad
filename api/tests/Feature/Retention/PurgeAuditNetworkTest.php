<?php

use App\Actions\Retention\PurgeAuditNetwork;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

/** The columns of an audit row other than the two the purge may empty. */
function auditKept(string $id): array
{
    $row = (array) DB::table('audit_logs')->where('id', $id)->first();
    unset($row['ip_hash'], $row['user_agent']);

    return $row;
}

it('empties ip_hash and user_agent of rows older than the window and keeps the boundary and newer rows', function () {
    // Given rows one second older than, exactly at, and one second inside the 365 day cutoff, plus a fresh one
    $cutoff = retentionNow()->subDays(365);
    $old = retentionAuditLog($cutoff->subSecond());
    $atBoundary = retentionAuditLog($cutoff);
    $inside = retentionAuditLog($cutoff->addSecond());
    $fresh = retentionAuditLog(retentionNow()->subMinute());

    // When the purge runs
    $purged = app(PurgeAuditNetwork::class)->purge(retentionNow());

    // Then only the older row lost its two columns; the boundary row and newer rows keep them
    expect($purged)->toBe(1);
    expect(DB::table('audit_logs')->whereNull('ip_hash')->whereNull('user_agent')->pluck('id')->all())->toBe([$old]);
    foreach ([$atBoundary, $inside, $fresh] as $id) {
        expect(DB::table('audit_logs')->where('id', $id)->first())->ip_hash->toBe(str_repeat('c', 64))->user_agent->toBe('Test Agent');
    }
});

it('keeps who, what, when, subject and meta of a purged row byte for byte, and never deletes a row', function () {
    // Given an old row of a real actor
    $actor = User::factory()->create();
    $id = retentionAuditLog(retentionNow()->subDays(700), ['actor_id' => $actor->id]);
    $before = auditKept($id);

    // When the purge runs
    app(PurgeAuditNetwork::class)->purge(retentionNow());

    // Then every other column is identical and the row count is unchanged
    expect(auditKept($id))->toBe($before)->and(DB::table('audit_logs')->count())->toBe(1);
});

it('empties a row that holds only one of the two columns', function (array $held) {
    // Given an old row that still holds just one of the columns
    $id = retentionAuditLog(retentionNow()->subDays(400), $held);

    // When the purge runs
    $purged = app(PurgeAuditNetwork::class)->purge(retentionNow());

    // Then it is selected and emptied
    expect($purged)->toBe(1);
    expect(DB::table('audit_logs')->where('id', $id)->first())->ip_hash->toBeNull()->user_agent->toBeNull();
})->with([
    'only ip_hash' => [['user_agent' => null]],
    'only user_agent' => [['ip_hash' => null]],
]);

it('is idempotent: a second run purges nothing', function () {
    // Given one old row, purged once, and one row that never had network data
    retentionAuditLog(retentionNow()->subDays(400));
    retentionAuditLog(retentionNow()->subDays(500), ['ip_hash' => null, 'user_agent' => null]);
    expect(app(PurgeAuditNetwork::class)->purge(retentionNow()))->toBe(1);

    // When it runs again
    $second = app(PurgeAuditNetwork::class)->purge(retentionNow());

    // Then nothing is selected again
    expect($second)->toBe(0)->and(DB::table('audit_logs')->count())->toBe(2);
});

it('moves the cutoff when the setting changes', function () {
    // Given a row 40 days old and the default window of 365 days
    $id = retentionAuditLog(retentionNow()->subDays(40));
    expect(app(PurgeAuditNetwork::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the window to the 30 day floor
    app(Settings::class)->set(SettingKey::RetentionAuditNetworkDays, 30, User::factory()->role(Role::Admin)->create());

    // Then the same row is now old enough
    expect(app(PurgeAuditNetwork::class)->purge(retentionNow()))->toBe(1);
    expect(DB::table('audit_logs')->where('id', $id)->value('ip_hash'))->toBeNull();
});

it('works through more than one chunk, oldest first, and ends cleanly when the last chunk is short', function () {
    // Given seven old rows and a chunk of two
    foreach (range(1, 7) as $i) {
        retentionAuditLog(retentionNow()->subDays(400 + $i));
    }
    $keep = retentionAuditLog(retentionNow()->subDay());
    $purge = retentionPurger(PurgeAuditNetwork::class, 2, 10);

    // When it runs
    $purged = $purge->purge(retentionNow());

    // Then all seven are emptied, the cap was not hit, and the fresh row keeps its columns
    expect($purged)->toBe(7)->and($purge->capped)->toBeFalse();
    expect(DB::table('audit_logs')->whereNotNull('ip_hash')->pluck('id')->all())->toBe([$keep]);
});

it('stops at the chunk cap, takes the oldest rows first and leaves the rest for the next run', function () {
    // Given exactly four old rows of different ages (and nothing else in the table), a chunk of one and a cap of two chunks
    expect(DB::table('audit_logs')->count())->toBe(0);
    $oldest = retentionAuditLog(retentionNow()->subDays(900));
    $older = retentionAuditLog(retentionNow()->subDays(800));
    $newer = retentionAuditLog(retentionNow()->subDays(500));
    $newest = retentionAuditLog(retentionNow()->subDays(400));
    expect(DB::table('audit_logs')->whereNotNull('ip_hash')->count())->toBe(4);
    $purge = retentionPurger(PurgeAuditNetwork::class, 1, 2);
    $limits = [];
    DB::listen(function ($query) use (&$limits) {
        if (str_starts_with(trim($query->sql), 'WITH picked')) {
            $limits[] = end($query->bindings);
        }
    });

    // When it runs twice
    $first = $purge->purge(retentionNow());
    $firstCapped = $purge->capped;
    $stillHeld = DB::table('audit_logs')->whereNotNull('ip_hash')->pluck('id')->sort()->values()->all();
    $second = $purge->purge(retentionNow());

    // Then every chunk statement carried LIMIT 1, the first run emptied the two oldest and flagged the cap, the second finished the others
    expect($limits)->toBe([1, 1, 1, 1]);
    expect($first)->toBe(2)->and($firstCapped)->toBeTrue()->and($stillHeld)->toBe(collect([$newer, $newest])->sort()->values()->all());
    expect($second)->toBe(2)->and($purge->capped)->toBeTrue()->and(DB::table('audit_logs')->whereNotNull('ip_hash')->count())->toBe(0);
    expect(DB::table('audit_logs')->whereIn('id', [$oldest, $older])->whereNull('ip_hash')->count())->toBe(2);
});

it('picks its rows with FOR UPDATE SKIP LOCKED, oldest first and bounded', function () {
    // Given two old rows
    $locked = retentionAuditLog(retentionNow()->subDays(500));
    $free = retentionAuditLog(retentionNow()->subDays(400));
    $sql = retentionCaptureSql(fn () => app(PurgeAuditNetwork::class)->purge(retentionNow()), 'WITH picked');

    // When the statement the purge ran is read
    // Then it picks the rows in a MATERIALIZED CTE with SKIP LOCKED (a row held by someone else is passed over and caught next run)
    expect($sql[0])->toContain('AS MATERIALIZED')->toContain('FOR UPDATE SKIP LOCKED')->toContain('ORDER BY created_at')->toContain('LIMIT');
    expect(DB::table('audit_logs')->whereIn('id', [$locked, $free])->whereNull('ip_hash')->count())->toBe(2);
});

it('uses the partial index for the select that picks the rows, with a predicate that matches it', function () {
    // Given a table of old rows where 99% are already emptied (the real situation after the first runs), analysed, and the SQL the purge really runs
    DB::insert(<<<'SQL'
        INSERT INTO audit_logs (id, action, meta, ip_hash, user_agent, created_at)
        SELECT lpad(i::text, 26, '0'), 'test.action', '{}', CASE WHEN i % 100 = 0 THEN repeat('c', 64) END, CASE WHEN i % 100 = 0 THEN 'Agent' END,
               ?::timestamptz - make_interval(mins => i)
        FROM generate_series(1, 5000) AS i
    SQL, [retentionNow()->subDays(400)->format('Y-m-d H:i:sP')]);
    DB::statement('ANALYZE audit_logs');
    // (the purge runs inside a savepoint that is rolled back, so the plan below sees the data as it was before the purge)
    DB::beginTransaction();
    $captured = retentionCaptureSql(fn () => app(PurgeAuditNetwork::class)->purge(retentionNow()), 'WITH picked');
    DB::rollBack();
    $sql = $captured[0] ?? '';
    $definition = DB::table('pg_indexes')->where('indexname', 'audit_logs_unpurged_network_index')->value('indexdef');

    // When its plan is read with scans and sorts that avoid indexes disabled
    $plan = retentionPlan($sql);

    // Then the picked-rows CTE is served by the partial index, which carries the same predicate as the WHERE
    expect($definition)->toContain('USING btree (created_at)')->toContain('ip_hash IS NOT NULL')->toContain('user_agent IS NOT NULL');
    expect($sql)->toContain('(ip_hash IS NOT NULL OR user_agent IS NOT NULL)');
    expect($plan)->toContain('audit_logs_unpurged_network_index')->not->toContain('Seq Scan on audit_logs');
});

it('locks no user or product row: the statements only touch audit_logs', function () {
    // Given an old row of a real actor
    $actor = User::factory()->create();
    retentionAuditLog(retentionNow()->subDays(500), ['actor_id' => $actor->id]);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });

    // When the purge runs
    app(PurgeAuditNetwork::class)->purge(retentionNow());

    // Then no statement names users or products
    foreach ($statements as $statement) {
        expect($statement)->not->toContain('users')->and($statement)->not->toContain('products');
    }
});

it('never reaches SQL with a corrupt setting value and falls back to the default window, not zero', function (mixed $stored) {
    // Given a stored value that bypassed validation, a row 100 days old (inside the default 365) and one 400 days old
    retentionStoreRaw(SettingKey::RetentionAuditNetworkDays, $stored);
    $inside = retentionAuditLog(retentionNow()->subDays(100));
    $outside = retentionAuditLog(retentionNow()->subDays(400));
    $bindings = [];
    DB::listen(function ($query) use (&$bindings) {
        array_push($bindings, ...$query->bindings);
    });

    // When the purge runs
    app(PurgeAuditNetwork::class)->purge(retentionNow());

    // Then the default (365 days) applied: the 100 day row keeps its columns, the 400 day row lost them, and no junk was bound
    expect(DB::table('audit_logs')->whereNotNull('ip_hash')->pluck('id')->all())->toBe([$inside]);
    expect(DB::table('audit_logs')->where('id', $outside)->value('ip_hash'))->toBeNull();
    foreach ($bindings as $binding) {
        expect($binding)->not->toBeArray()->and($binding)->not->toBeObject()->and((string) $binding)->not->toContain('junk');
    }
})->with([
    'zero' => [0], 'negative' => [-5], 'below the floor' => [3], 'above the ceiling' => [999999], 'float' => [1.5], 'bool' => [true],
    'numeric string' => ['30'], 'garbage string' => ['junk'], 'null' => [null], 'array' => [['junk']], 'object' => [['junk' => 1]], 'empty array' => [[]],
]);

it('rolls the chunk back with its flag when the update fails, and leaves the guard in force', function () {
    // Given an old row and a purge whose chunk sets the flag, then fails
    $id = retentionAuditLog(retentionNow()->subDays(500));
    $purge = new class(app(Settings::class)) extends PurgeAuditNetwork
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('SELECT set_config(?, ?, true)', [self::FLAG, 'on']);
            DB::select('select 1/0');

            return 0;
        }
    };

    // When the run fails
    $run = fn () => $purge->purge(retentionNow());

    // Then the error surfaces, nothing changed, and the flag is not left on in this transaction
    expect($run)->toThrow(QueryException::class);
    expect(DB::table('audit_logs')->where('id', $id)->value('ip_hash'))->not->toBeNull();
    expect(DB::selectOne("select coalesce(current_setting('launchpad.audit_purge', true), '') as v")->v)->not->toBe('on');
});

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve eligible old rows, a chunk of three, and a planner pushed towards a given join strategy
    foreach (range(1, 12) as $i) {
        retentionAuditLog(retentionNow()->subDays(400 + $i));
    }
    DB::statement('ANALYZE audit_logs');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeAuditNetwork::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were emptied (the oldest) and nine are untouched
    expect($purged)->toBe(3)->and(DB::table('audit_logs')->whereNull('ip_hash')->count())->toBe(3)->and(DB::table('audit_logs')->whereNotNull('ip_hash')->count())->toBe(9);
})->with(retentionPlannerModes());
