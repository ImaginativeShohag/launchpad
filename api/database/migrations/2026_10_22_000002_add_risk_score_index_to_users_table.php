<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The staff list of risky members, worst first. Only members with a score are in it (most members sit at 0), and the
     * id runs DESC like the score so a keyset cursor has one direction.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS users_risk_score_index ON users (risk_score DESC, id DESC) WHERE risk_score > 0');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_risk_score_index');
    }
};
