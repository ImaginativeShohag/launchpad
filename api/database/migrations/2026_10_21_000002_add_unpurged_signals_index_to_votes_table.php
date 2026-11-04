<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Votes that still carry an ip hash, a device hash or a user agent. The retention purge looks for exactly these
     * rows of old launches; once purged a row leaves the index, so the daily scan stays small however old the data is.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS votes_unpurged_signals_index ON votes (product_id)
                WHERE ip_hash IS NOT NULL OR device_hash IS NOT NULL OR user_agent IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS votes_unpurged_signals_index');
    }
};
