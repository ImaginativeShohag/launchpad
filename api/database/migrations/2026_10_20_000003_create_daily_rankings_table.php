<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_rankings', function (Blueprint $table) {
            $table->date('launch_date');
            $table->foreignUlid('product_id')->constrained();
            $table->smallInteger('rank');
            $table->integer('votes_count');
            $table->double('score');
            $table->timestampTz('frozen_at');

            $table->primary(['launch_date', 'product_id']);
            $table->unique(['launch_date', 'rank']);
        });

        DB::statement('ALTER TABLE daily_rankings ADD CONSTRAINT daily_rankings_rank_positive CHECK (rank >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_rankings');
    }
};
