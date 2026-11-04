<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NOT VALID takes only a brief lock (no table scan under ACCESS EXCLUSIVE); VALIDATE then scans under SHARE UPDATE
        // EXCLUSIVE, which lets reads and writes of products continue on a table that already holds rows.
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_comments_count_nonneg CHECK (comments_count >= 0) NOT VALID');
        DB::statement('ALTER TABLE products VALIDATE CONSTRAINT products_comments_count_nonneg');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_comments_count_nonneg');
    }
};
