<?php

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('refuses to update or delete audit log rows', function () {
    // Given an audit log row
    $log = AuditLog::factory()->create();

    // When someone tries to change or remove it
    $update = fn () => DB::transaction(
        fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered'])
    );
    $delete = fn () => DB::transaction(
        fn () => DB::table('audit_logs')->where('id', $log->id)->delete()
    );

    // Then the database rejects both with the trigger's message
    expect($update)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect($delete)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect(DB::table('audit_logs')->where('id', $log->id)->value('action'))->toBe($log->action);
});

it('has exactly one immutability function and all three triggers on audit_logs', function () {
    // Given the migrated schema

    // When we inspect the catalogue
    $functions = DB::table('pg_proc')->where('proname', 'audit_logs_immutable')->count();
    $triggers = DB::table('pg_trigger')
        ->where('tgrelid', DB::raw("'audit_logs'::regclass"))
        ->where('tgisinternal', false)
        ->pluck('tgname')->sort()->values()->all();

    // Then the function exists once and all triggers are attached
    expect($functions)->toBe(1);
    expect($triggers)->toBe(['audit_logs_no_delete', 'audit_logs_no_truncate', 'audit_logs_no_update']);
});

it('refuses to truncate the audit log', function () {
    // Given an audit log row
    $log = AuditLog::factory()->create();

    // When someone tries to truncate the table
    $truncate = fn () => DB::transaction(fn () => DB::unprepared('TRUNCATE TABLE audit_logs'));

    // Then the statement trigger rejects it and the row survives
    expect($truncate)->toThrow(QueryException::class, 'audit_logs is append-only');
    expect(DB::table('audit_logs')->where('id', $log->id)->exists())->toBeTrue();
});

it('refuses TRUNCATE ... CASCADE too', function () {
    // Given an audit log row
    AuditLog::factory()->create();

    // When someone truncates with CASCADE
    $truncate = fn () => DB::transaction(fn () => DB::unprepared('TRUNCATE TABLE audit_logs CASCADE'));

    // Then it is rejected as well
    expect($truncate)->toThrow(QueryException::class, 'audit_logs is append-only');
});
