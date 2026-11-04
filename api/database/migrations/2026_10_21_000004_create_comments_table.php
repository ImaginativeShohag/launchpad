<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Plain foreign keys: no cascade, a comment is history (deleting one is a column, never a row delete).
            $table->foreignUlid('product_id')->constrained();
            $table->foreignUlid('user_id')->constrained('users');
            $table->ulid('parent_id')->nullable();
            $table->foreignUlid('reply_to_user_id')->nullable()->constrained('users');
            $table->text('body');
            $table->timestampTz('edited_at', 6)->nullable();
            $table->timestampTz('removed_at', 6)->nullable();
            $table->foreignUlid('removed_by')->nullable()->constrained('users');
            $table->string('removed_reason', 255)->nullable();
            // Visible replies of a top-level comment; the Top sort needs it and it avoids a per-row aggregate.
            $table->integer('replies_count')->default(0);
            // created_at sits in keyset cursors, so it keeps microseconds.
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->nullable();
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        // The self reference is added once the primary key exists.
        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('comments');
        });

        DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_body_length CHECK (char_length(body) <= 2000)');
        DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_body_present CHECK (deleted_at IS NOT NULL OR char_length(body) >= 1)');
        DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_reply_to_needs_parent CHECK (parent_id IS NOT NULL OR reply_to_user_id IS NULL)');
        DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_replies_count_nonneg CHECK (replies_count >= 0)');
        DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_removed_pair CHECK ((removed_at IS NULL) = (removed_by IS NULL))');

        // One level of replies, enforced by the database: on INSERT the parent must exist, be top-level and sit on the same
        // product. product_id, user_id and parent_id never change afterwards (the actions read them without a lock).
        // CREATE OR REPLACE because migrate:fresh drops tables only, never functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION comments_enforce_reply_depth() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.parent_id IS DISTINCT FROM OLD.parent_id
                        OR NEW.product_id IS DISTINCT FROM OLD.product_id
                        OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                        RAISE EXCEPTION 'product_id, user_id and parent_id of a comment cannot change' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.parent_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM comments p
                    WHERE p.id = NEW.parent_id AND p.parent_id IS NULL AND p.product_id = NEW.product_id
                ) THEN
                    RAISE EXCEPTION 'a reply needs a top-level parent comment on the same product' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared('CREATE TRIGGER comments_reply_depth BEFORE INSERT OR UPDATE OF parent_id, product_id, user_id ON comments FOR EACH ROW EXECUTE FUNCTION comments_enforce_reply_depth()');

        // Every keyset ordering has its index (Blueprint cannot express DESC or WHERE).
        DB::statement('CREATE INDEX comments_product_top_index ON comments (product_id, replies_count DESC, created_at DESC, id DESC) WHERE parent_id IS NULL');
        DB::statement('CREATE INDEX comments_product_newest_index ON comments (product_id, created_at DESC, id DESC) WHERE parent_id IS NULL');
        DB::statement('CREATE INDEX comments_parent_index ON comments (parent_id, created_at, id) WHERE parent_id IS NOT NULL');
        DB::statement('CREATE INDEX comments_user_index ON comments (user_id)');
        // Foreign-key lookups of the account clean-up and of user deletion; most rows have neither column set.
        DB::statement('CREATE INDEX comments_reply_to_user_index ON comments (reply_to_user_id) WHERE reply_to_user_id IS NOT NULL');
        DB::statement('CREATE INDEX comments_removed_by_index ON comments (removed_by) WHERE removed_by IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        DB::statement('DROP FUNCTION IF EXISTS comments_enforce_reply_depth()');
    }
};
