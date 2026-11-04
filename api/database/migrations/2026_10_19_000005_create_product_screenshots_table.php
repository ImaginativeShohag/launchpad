<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_screenshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            // Plain smallint, unique and NOT deferrable: reordering uses a two-phase update (see ScreenshotOrder).
            $table->smallInteger('position');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('alt_text', 200)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_screenshots');
    }
};
