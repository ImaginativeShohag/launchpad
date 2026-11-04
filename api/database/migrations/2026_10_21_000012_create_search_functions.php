<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Search needs `unaccent` and `pg_trgm` (both trusted contrib extensions, so the database owner may create them) and
     * two SQL functions. migrate:fresh drops tables but keeps extensions and functions, hence IF NOT EXISTS and
     * CREATE OR REPLACE (never a plain CREATE).
     *
     * launchpad_fold is IMMUTABLE on purpose: unaccent() itself is only STABLE, but the dictionary is named explicitly
     * (schema-qualified), so the result cannot change between calls, and only an immutable function may sit inside a
     * generated column or an index. Changing launchpad_fold or the unaccent rules later means rebuilding both generated
     * columns and both indexes in one maintenance migration; CREATE OR REPLACE alone does not recompute stored values.
     *
     * launchpad_prefix_tsquery is query-side only (STABLE, never used in an index): the typed last token as a prefix
     * tsquery. It is built from plainto_tsquery, so no input can produce a tsquery syntax error.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS unaccent;
            CREATE EXTENSION IF NOT EXISTS pg_trgm;

            CREATE OR REPLACE FUNCTION public.launchpad_fold(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$
                SELECT btrim(regexp_replace(
                    lower(public.unaccent('public.unaccent', normalize($1, NFKC))),
                    '[\s!-/:-@\[-`{-~]+', ' ', 'g'))
            $$;

            CREATE OR REPLACE FUNCTION public.launchpad_prefix_tsquery(text) RETURNS tsquery
                LANGUAGE sql STABLE PARALLEL SAFE STRICT AS $$
                SELECT CASE WHEN numnode(q) = 0 THEN q ELSE (q::text || ':*')::tsquery END
                FROM (SELECT plainto_tsquery('simple', public.launchpad_fold($1)) AS q) s
            $$;
        SQL);
    }

    /** The extensions stay: they are shared infrastructure, dropping them needs ownership and nothing else here uses them. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.launchpad_prefix_tsquery(text);
            DROP FUNCTION IF EXISTS public.launchpad_fold(text);
        SQL);
    }
};
