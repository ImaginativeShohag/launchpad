<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `submitted_at` is the token a reviewer's decision echoes back (stale_submission check). Laravel creates
     * timestamps with precision 0, which would let a withdraw-edit-resubmit inside one second reuse the old token.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN submitted_at TYPE timestamp(6) with time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN submitted_at TYPE timestamp(0) with time zone');
    }
};
