<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Serves retention pruning and time-range scans over the whole auth_events table. */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS auth_events_created_at_index ON auth_events (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS auth_events_created_at_index');
    }
};
