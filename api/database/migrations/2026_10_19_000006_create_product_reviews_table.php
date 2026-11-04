<?php

use App\Enums\ReviewDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Plain foreign keys: a cascade or SET NULL would be an UPDATE/DELETE that the append-only trigger blocks.
            $table->foreignUlid('product_id')->constrained();
            $table->foreignUlid('reviewer_id')->constrained('users');
            $table->enum('decision', ReviewDecision::values());
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['product_id', 'created_at', 'id'], 'product_reviews_product_id_created_at_id_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_reviews ADD CONSTRAINT product_reviews_note_required
                CHECK (decision = 'approved' OR (note IS NOT NULL AND char_length(btrim(note)) > 0))
        SQL);

        // migrate:fresh drops tables but not functions, so the function is CREATE OR REPLACE and triggers are dropped first.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION product_reviews_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'product_reviews is append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS product_reviews_no_update ON product_reviews;
            DROP TRIGGER IF EXISTS product_reviews_no_delete ON product_reviews;
            DROP TRIGGER IF EXISTS product_reviews_no_truncate ON product_reviews;

            CREATE TRIGGER product_reviews_no_update BEFORE UPDATE ON product_reviews
                FOR EACH ROW EXECUTE FUNCTION product_reviews_immutable();
            CREATE TRIGGER product_reviews_no_delete BEFORE DELETE ON product_reviews
                FOR EACH ROW EXECUTE FUNCTION product_reviews_immutable();
            CREATE TRIGGER product_reviews_no_truncate BEFORE TRUNCATE ON product_reviews
                FOR EACH STATEMENT EXECUTE FUNCTION product_reviews_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        DB::unprepared('DROP FUNCTION IF EXISTS product_reviews_immutable()');
    }
};
