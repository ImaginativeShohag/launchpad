<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a product's counted views came from, per UTC day: a source key (allowlist key, own host, or a host) and a
        // count. No person, IP, agent or full URL is ever stored. The primary key serves every read (a prefix range).
        Schema::create('product_referrers_daily', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained();
            $table->date('day');
            $table->string('source', 255);
            $table->integer('visits')->default(0);

            $table->primary(['product_id', 'day', 'source']);
        });

        DB::statement('ALTER TABLE product_referrers_daily ADD CONSTRAINT product_referrers_daily_visits_nonneg CHECK (visits >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_referrers_daily');
    }
};
