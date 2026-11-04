<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_snapshots', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained();
            $table->timestampTz('captured_at');
            $table->smallInteger('rank');
            $table->double('score');

            $table->primary(['product_id', 'captured_at']);
        });

        DB::statement('ALTER TABLE rank_snapshots ADD CONSTRAINT rank_snapshots_rank_positive CHECK (rank >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_snapshots');
    }
};
