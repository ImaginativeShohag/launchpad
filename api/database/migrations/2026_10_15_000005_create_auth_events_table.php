<?php

use App\Enums\AuthEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained();
            $table->enum('event', AuthEventType::values());
            $table->string('ip_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index('ip_hash');
            $table->index('device_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
