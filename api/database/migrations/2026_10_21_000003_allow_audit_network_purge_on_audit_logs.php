<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * audit_logs stays append-only for everything except one narrow exception: the retention purge may set `ip_hash` and
     * `user_agent` to NULL. An UPDATE passes only when ALL of these hold: the transaction set the transaction-local
     * flag `launchpad.audit_purge` to 'on' (only PurgeAuditNetwork does), both columns end up NULL, and every other
     * column is unchanged. Everything else (any other UPDATE, DELETE, TRUNCATE) still raises. The same function backs
     * the row triggers and the statement-level TRUNCATE trigger; it reads OLD/NEW only inside the UPDATE branch.
     *
     * Not a privilege boundary: any role that can run SQL can set the flag. It stops accidents and application bugs;
     * the README explains how to revoke UPDATE from the application role for a hard guarantee.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF coalesce(current_setting('launchpad.audit_purge', true), '') = 'on'
                       AND NEW.ip_hash IS NULL
                       AND NEW.user_agent IS NULL
                       AND (to_jsonb(NEW) - 'ip_hash' - 'user_agent') = (to_jsonb(OLD) - 'ip_hash' - 'user_agent')
                    THEN
                        RETURN NEW;
                    END IF;
                END IF;

                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // Rows that still hold the two columns, oldest first: the nightly purge reads only these, and a purged row leaves the index.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS audit_logs_unpurged_network_index ON audit_logs (created_at)
                WHERE ip_hash IS NOT NULL OR user_agent IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_logs_unpurged_network_index');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
