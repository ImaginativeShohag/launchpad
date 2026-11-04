<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->string('emoji', 8)->nullable();
            $table->string('description', 280)->nullable();
            $table->smallInteger('position')->default(0);
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE topics ADD CONSTRAINT topics_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
