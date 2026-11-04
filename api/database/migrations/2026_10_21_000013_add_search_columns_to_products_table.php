<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two stored generated columns that PostgreSQL keeps in sync itself (no trigger, no job): the weighted search_vector
     * (name A, tagline B, description C, capped at 20000 characters) and the folded name for typo matching. The old
     * plain nullable search_vector was never written, so it is dropped and re-added in the same statement. The table is
     * rewritten once and every existing row is back-filled.
     *
     * Both GIN indexes are partial and carry the same predicate text as products_live_votes_launch_date_id_index, so a
     * query that states "status = 'live' AND deleted_at IS NULL" can use them and non-live text is never indexed.
     * Maintenance window: ADD COLUMN ... GENERATED STORED rewrites the whole table under an ACCESS EXCLUSIVE lock (reads
     * and writes wait), and launchpad_fold runs four times per row (three for the vector, one for name_search). The plain
     * CREATE INDEX statements below hold a SHARE lock (writes wait) while they build. On a big table, run this migration in
     * a quiet window, or create the two indexes by hand first with CREATE INDEX CONCURRENTLY (same names and definitions
     * as below; they need the columns, so that means adding the columns first and skipping the index statements here).
     *
     * Blueprint cannot express generated columns, GIN, gin_trgm_ops or partial indexes, hence raw SQL.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                DROP COLUMN search_vector,
                ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('simple', launchpad_fold(name)), 'A')
                    || setweight(to_tsvector('simple', launchpad_fold(coalesce(tagline, ''))), 'B')
                    || setweight(to_tsvector('simple', launchpad_fold(left(coalesce(description, ''), 20000))), 'C')
                ) STORED,
                ADD COLUMN name_search text GENERATED ALWAYS AS (launchpad_fold(name)) STORED;

            CREATE INDEX products_search_vector_gin ON products USING gin (search_vector)
                WHERE status = 'live' AND deleted_at IS NULL;
            CREATE INDEX products_name_search_trgm_gin ON products USING gin (name_search gin_trgm_ops)
                WHERE status = 'live' AND deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS products_name_search_trgm_gin;
            DROP INDEX IF EXISTS products_search_vector_gin;
            ALTER TABLE products
                DROP COLUMN name_search,
                DROP COLUMN search_vector,
                ADD COLUMN search_vector tsvector;
        SQL);
    }
};
