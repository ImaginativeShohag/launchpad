<?php

use App\Actions\Retention\PurgeAuditNetwork;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

const AUDIT_FLAG = PurgeAuditNetwork::FLAG;

/** Runs $callback in a savepoint, with the purge flag set to $flag (null: not set), so a raised error does not poison the test transaction. */
function auditWithFlag(?string $flag, callable $callback): mixed
{
    return DB::transaction(function () use ($flag, $callback) {
        if ($flag !== null) {
            DB::select('SELECT set_config(?, ?, true)', [AUDIT_FLAG, $flag]);
        }

        return $callback();
    });
}

it('lets an UPDATE through only when the flag is on and just ip_hash and user_agent become NULL', function () {
    // Given an audit row with both network columns
    $id = retentionAuditLog(retentionNow()->subDays(500));

    // When the flag is on and exactly those two columns are set to NULL
    $changed = auditWithFlag('on', fn () => DB::table('audit_logs')->where('id', $id)->update(['ip_hash' => null, 'user_agent' => null]));

    // Then it succeeds
    expect($changed)->toBe(1);
    expect(DB::table('audit_logs')->where('id', $id)->first())->ip_hash->toBeNull()->user_agent->toBeNull();
});

it('still raises when the flag is on but anything else changes', function (array $change) {
    // Given an audit row
    $id = retentionAuditLog(retentionNow()->subDays(500));
    $before = (array) DB::table('audit_logs')->where('id', $id)->first();

    // When the flag is on and the update touches another column (with or without also emptying the two columns)
    $update = fn () => auditWithFlag('on', fn () => DB::table('audit_logs')->where('id', $id)->update($change));

    // Then the guard raises and the row is untouched
    expect($update)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect((array) DB::table('audit_logs')->where('id', $id)->first())->toBe($before);
})->with([
    'action' => [['action' => 'tampered']],
    'action with network emptied' => [['action' => 'tampered', 'ip_hash' => null, 'user_agent' => null]],
    'meta' => [['meta' => json_encode(['x' => 1]), 'ip_hash' => null, 'user_agent' => null]],
    'created_at' => [['created_at' => '2020-01-01 00:00:00+00', 'ip_hash' => null, 'user_agent' => null]],
    'subject_type' => [['subject_type' => 'user', 'ip_hash' => null, 'user_agent' => null]],
    'subject_id' => [['subject_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'ip_hash' => null, 'user_agent' => null]],
    'actor_id' => [['actor_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'ip_hash' => null, 'user_agent' => null]],
    'id' => [['id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'ip_hash' => null, 'user_agent' => null]],
]);

it('still raises when the flag is on but a network column gets a non-null value', function (array $change) {
    // Given an audit row
    $id = retentionAuditLog(retentionNow()->subDays(500));

    // When the flag is on and a network column is rewritten to a value, or only one of the two is emptied
    $update = fn () => auditWithFlag('on', fn () => DB::table('audit_logs')->where('id', $id)->update($change));

    // Then the guard raises and both columns are untouched
    expect($update)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect(DB::table('audit_logs')->where('id', $id)->first())->ip_hash->toBe(str_repeat('c', 64))->user_agent->toBe('Test Agent');
})->with([
    'new ip_hash' => [['ip_hash' => str_repeat('d', 64)]],
    'new user_agent' => [['user_agent' => 'Other']],
    'both new' => [['ip_hash' => str_repeat('d', 64), 'user_agent' => 'Other']],
    'only ip_hash emptied' => [['ip_hash' => null]],
    'only user_agent emptied' => [['user_agent' => null]],
    'ip_hash emptied, user_agent rewritten' => [['ip_hash' => null, 'user_agent' => 'Other']],
]);

it('raises on the narrow update when the flag is missing or not exactly on', function (?string $flag) {
    // Given an audit row
    $id = retentionAuditLog(retentionNow()->subDays(500));

    // When ip_hash and user_agent are emptied without the flag, or with a wrong value
    $update = fn () => auditWithFlag($flag, fn () => DB::table('audit_logs')->where('id', $id)->update(['ip_hash' => null, 'user_agent' => null]));

    // Then the guard raises and the row keeps its columns
    expect($update)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect(DB::table('audit_logs')->where('id', $id)->value('ip_hash'))->toBe(str_repeat('c', 64));
})->with([
    'never set' => [null], 'off' => ['off'], 'empty' => [''], 'ON' => ['ON'], 'true' => ['true'], '1' => ['1'], 'on with space' => ['on '],
]);

it('keeps refusing every DELETE and TRUNCATE, even with the flag on', function () {
    // Given an audit row and the flag on
    $id = retentionAuditLog(retentionNow()->subDays(500));

    // When someone deletes or truncates
    $delete = fn () => auditWithFlag('on', fn () => DB::table('audit_logs')->where('id', $id)->delete());
    $truncate = fn () => auditWithFlag('on', fn () => DB::unprepared('TRUNCATE TABLE audit_logs'));
    $deleteAfterEmptying = fn () => auditWithFlag('on', function () use ($id) {
        DB::table('audit_logs')->where('id', $id)->update(['ip_hash' => null, 'user_agent' => null]);
        DB::table('audit_logs')->where('id', $id)->delete();
    });

    // Then all three raise and the row survives
    expect($delete)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect($truncate)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect($deleteAfterEmptying)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect(DB::table('audit_logs')->where('id', $id)->exists())->toBeTrue();
});

it('does not let one allowed UPDATE open the door for a second one in the same transaction after the purge', function () {
    // Given an old row, purged by the real action (which switches the flag off right after its statement)
    $id = retentionAuditLog(retentionNow()->subDays(500));
    $other = retentionAuditLog(retentionNow()->subDay());
    app(PurgeAuditNetwork::class)->purge(retentionNow());

    // When a later statement in the same outer transaction empties another row without setting the flag
    $update = fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $other)->update(['ip_hash' => null, 'user_agent' => null]));

    // Then the guard raises again
    expect(DB::table('audit_logs')->where('id', $id)->value('ip_hash'))->toBeNull();
    expect($update)->toThrow(QueryException::class, 'audit_logs is append-only');
});

it('does not leak the flag out of a committed purge transaction on a real connection', function () {
    // Given a second, separate connection (no test wrapper around it) as the default connection
    config(['database.connections.audit_leak' => config('database.connections.'.config('database.default'))]);
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('audit_leak');

    try {
        // When the real purge commits its chunk(s) on it
        app(PurgeAuditNetwork::class)->purge(retentionNow());
        $afterCommit = DB::connection('audit_leak')->selectOne("select coalesce(current_setting('launchpad.audit_purge', true), '') as v")->v;
        $level = DB::connection('audit_leak')->transactionLevel();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('audit_leak');
    }

    // Then the connection is outside any transaction and the flag is gone (not 'on')
    expect($level)->toBe(0)->and($afterCommit)->not->toBe('on');
});

it('shows the flag reverts after COMMIT on a real connection even when set by hand', function () {
    // Given a separate connection
    config(['database.connections.audit_leak' => config('database.connections.'.config('database.default'))]);
    $connection = DB::connection('audit_leak');

    try {
        // When a transaction sets the transaction-local flag and commits
        $connection->beginTransaction();
        $connection->select('SELECT set_config(?, ?, true)', [AUDIT_FLAG, 'on']);
        $inside = $connection->selectOne("select current_setting('launchpad.audit_purge', true) as v")->v;
        $connection->commit();
        $after = $connection->selectOne("select coalesce(current_setting('launchpad.audit_purge', true), '') as v")->v;
    } finally {
        DB::purge('audit_leak');
    }

    // Then it was on inside and is not on afterwards
    expect($inside)->toBe('on')->and($after)->not->toBe('on');
});

it('has the same single function and three triggers as before, plus the partial index', function () {
    // Given the migrated schema
    // When the catalogue is read
    $functions = DB::table('pg_proc')->where('proname', 'audit_logs_immutable')->count();
    $triggers = DB::table('pg_trigger')->where('tgrelid', DB::raw("'audit_logs'::regclass"))->where('tgisinternal', false)->pluck('tgname')->sort()->values()->all();
    $index = DB::table('pg_indexes')->where('indexname', 'audit_logs_unpurged_network_index')->value('indexdef');

    // Then nothing was added or dropped except the index
    expect($functions)->toBe(1)->and($triggers)->toBe(['audit_logs_no_delete', 'audit_logs_no_truncate', 'audit_logs_no_update']);
    expect($index)->toContain('WHERE ((ip_hash IS NOT NULL) OR (user_agent IS NOT NULL))');
});
