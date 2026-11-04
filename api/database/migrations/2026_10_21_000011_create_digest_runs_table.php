<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per digest kind and covered period: the products chosen once for everyone, and a resumable cursor.
        Schema::create('digest_runs', function (Blueprint $table) {
            $table->string('kind', 16);
            // The covered day (daily) or the Monday of the covered week (weekly), UTC.
            $table->date('period_date');
            $table->jsonb('product_ids');
            // The last user id fully processed; the next chunk continues after it.
            $table->char('last_user_id', 26)->nullable();
            $table->integer('enqueued')->default(0);
            $table->timestampTz('started_at', 6);
            // NULL while the run is open (running, or abandoned after its send day).
            $table->timestampTz('completed_at', 6)->nullable();

            $table->primary(['kind', 'period_date']);
        });

        DB::statement("ALTER TABLE digest_runs ADD CONSTRAINT digest_runs_kind_valid CHECK (kind IN ('daily_top5', 'weekly_best'))");
        DB::statement("ALTER TABLE digest_runs ADD CONSTRAINT digest_runs_product_ids_valid CHECK (jsonb_typeof(product_ids) = 'array' AND jsonb_array_length(product_ids) <= 5)");
        DB::statement('ALTER TABLE digest_runs ADD CONSTRAINT digest_runs_enqueued_valid CHECK (enqueued >= 0)');
        // The retention purge deletes by period_date alone (the primary key leads with kind).
        DB::statement('CREATE INDEX digest_runs_period_date_index ON digest_runs (period_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_runs');
    }
};
