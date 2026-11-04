<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Serves the default newest-first audit list (keyset order created_at DESC, id DESC). */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_created_at_id_index ON audit_logs (created_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_logs_created_at_id_index');
    }
};
