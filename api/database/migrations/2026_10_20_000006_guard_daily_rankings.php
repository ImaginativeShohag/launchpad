<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A frozen day is final: the app only inserts. Same shape as the audit_logs guard. The function only raises and
     * never reads OLD/NEW, which makes it safe for the FOR EACH STATEMENT truncate trigger (row triggers do not fire
     * on TRUNCATE). CREATE OR REPLACE and DROP IF EXISTS because migrate:fresh drops tables but keeps functions.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION daily_rankings_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'daily_rankings is insert-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS daily_rankings_no_update ON daily_rankings;
            CREATE TRIGGER daily_rankings_no_update BEFORE UPDATE ON daily_rankings
                FOR EACH ROW EXECUTE FUNCTION daily_rankings_immutable();
            DROP TRIGGER IF EXISTS daily_rankings_no_delete ON daily_rankings;
            CREATE TRIGGER daily_rankings_no_delete BEFORE DELETE ON daily_rankings
                FOR EACH ROW EXECUTE FUNCTION daily_rankings_immutable();
            DROP TRIGGER IF EXISTS daily_rankings_no_truncate ON daily_rankings;
            CREATE TRIGGER daily_rankings_no_truncate BEFORE TRUNCATE ON daily_rankings
                FOR EACH STATEMENT EXECUTE FUNCTION daily_rankings_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS daily_rankings_no_update ON daily_rankings;
            DROP TRIGGER IF EXISTS daily_rankings_no_delete ON daily_rankings;
            DROP TRIGGER IF EXISTS daily_rankings_no_truncate ON daily_rankings;
            DROP FUNCTION IF EXISTS daily_rankings_immutable();
        SQL);
    }
};
