<?php

use App\Enums\LinkKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', LinkKind::values());
            $table->string('label', 40)->nullable();
            $table->string('url', 2048);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();

            $table->unique(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_links');
    }
};
