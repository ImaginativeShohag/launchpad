<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_hourly_stats', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained();
            $table->timestampTz('hour_start');
            // Net votes of the hour. No CHECK: a negative sum can only come from a bug, and tests assert the sums.
            $table->integer('votes')->default(0);
            $table->integer('views')->default(0);

            $table->primary(['product_id', 'hour_start']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_hourly_stats ADD CONSTRAINT product_hourly_stats_hour_start_on_the_hour
                CHECK (date_trunc('hour', hour_start, 'UTC') = hour_start)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_hourly_stats');
    }
};
