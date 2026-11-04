<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A pending product without `submitted_at` could never be decided (the decision echoes that token). */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_pending_needs_submitted_at
                CHECK (status <> 'pending' OR submitted_at IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_pending_needs_submitted_at');
    }
};
