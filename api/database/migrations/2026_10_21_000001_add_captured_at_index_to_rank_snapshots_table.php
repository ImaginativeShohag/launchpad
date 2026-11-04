<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The primary key (product_id, captured_at) cannot serve "everything older than X"; the retention purge needs this. */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS rank_snapshots_captured_at_index ON rank_snapshots (captured_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rank_snapshots_captured_at_index');
    }
};
