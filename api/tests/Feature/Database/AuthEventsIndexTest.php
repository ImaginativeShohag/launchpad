<?php

use Illuminate\Support\Facades\DB;

it('indexes auth_events by created_at for retention and time-range scans', function () {
    // Given the migrated schema

    // When we read the index definitions
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'auth_events')
        ->where('indexname', 'auth_events_created_at_index')
        ->value('indexdef');

    // Then a plain btree index on created_at exists
    expect($definition)->toContain('USING btree (created_at)');
});
