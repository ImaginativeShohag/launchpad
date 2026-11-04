<?php

use App\Enums\VoteStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Plain foreign keys: no cascade, a vote is history (voiding is a status, never a delete).
            $table->foreignUlid('user_id')->constrained('users');
            $table->foreignUlid('product_id')->constrained();
            $table->enum('status', VoteStatus::values())->default(VoteStatus::Counted->value);
            $table->timestampTz('voted_at');
            $table->timestampTz('removed_at')->nullable();
            $table->smallInteger('toggle_count')->default(0);
            $table->string('ip_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            // One vote per member per product: a re-vote reuses the row.
            $table->unique(['user_id', 'product_id']);
            $table->index(['product_id', 'voted_at']);
            $table->index('ip_hash');
            $table->index('device_hash');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE votes ADD CONSTRAINT votes_voided_matches_status
                CHECK ((status = 'voided') = (voided_at IS NOT NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
