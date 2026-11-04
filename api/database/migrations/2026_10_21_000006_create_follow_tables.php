<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            // Plain foreign keys: a follow is a leaf row, DeleteAccount and DeleteTopic delete them explicitly.
            $table->foreignUlid('follower_id')->constrained('users');
            $table->foreignUlid('followed_id')->constrained('users');
            // created_at sits in the keyset cursor of GET /me/follows/users, so it keeps microseconds.
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->primary(['follower_id', 'followed_id']);
            $table->index('followed_id');
        });

        DB::statement('ALTER TABLE user_follows ADD CONSTRAINT user_follows_not_self CHECK (follower_id <> followed_id)');
        DB::statement('CREATE INDEX user_follows_follower_created_index ON user_follows (follower_id, created_at DESC, followed_id DESC)');

        Schema::create('topic_follows', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained('users');
            $table->foreignUlid('topic_id')->constrained('topics');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->primary(['user_id', 'topic_id']);
            $table->index('topic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_follows');
        Schema::dropIfExists('user_follows');
    }
};
