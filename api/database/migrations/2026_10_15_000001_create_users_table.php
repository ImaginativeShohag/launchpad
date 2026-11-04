<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('handle', 30)->unique();
            $table->string('name', 80);
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->timestampTz('pending_email_expires_at')->nullable();
            $table->string('password')->nullable();
            $table->enum('role', Role::values())->default(Role::Member->value);
            $table->string('headline', 120)->nullable();
            $table->string('avatar_path')->nullable();
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspended_reason', 500)->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->index('role');
            $table->index('suspended_at');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase CHECK (email = lower(email))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_handle_lowercase CHECK (handle = lower(handle))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_risk_score_range CHECK (risk_score BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
