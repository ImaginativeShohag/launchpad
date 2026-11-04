<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The tops order by votes_count desc, launch_date desc, id and cut a launch-date window (or none, for all time),
        // so the vote count leads. Only live, not-deleted rows can appear in a top. Blueprint cannot express DESC or WHERE.
        DB::statement("CREATE INDEX products_live_votes_launch_date_id_index ON products (votes_count DESC, launch_date DESC, id) WHERE status = 'live' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_live_votes_launch_date_id_index');
    }
};
