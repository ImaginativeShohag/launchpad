<?php

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('maker_id')->constrained('users');
            $table->string('slug', 80)->unique();
            $table->string('name', 60);
            $table->string('tagline', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('logo_path')->nullable();
            $table->enum('status', ProductStatus::values())->default(ProductStatus::Draft->value);
            $table->date('launch_date')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('launched_at')->nullable();
            $table->timestampTz('taken_down_at')->nullable();
            $table->foreignUlid('taken_down_by')->nullable()->constrained('users');
            $table->string('takedown_reason', 500)->nullable();
            // Owned by later sub-projects (3 and 6); created now so they need no migration here. Never written in this one.
            $table->integer('votes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->double('hot_score')->default(0);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->index(['status', 'launch_date'], 'products_status_launch_date_index');
            $table->index(['launch_date', 'hot_score'], 'products_launch_date_hot_score_index');
            // Per-day cap lookup and the maker's own listings (D9).
            $table->index(['maker_id', 'launch_date', 'id'], 'products_maker_id_launch_date_id_index');
            // Review queue ordering: oldest submission first, id as the tie-breaker.
            $table->index(['status', 'submitted_at', 'id'], 'products_status_submitted_at_id_index');
            $table->index(['status', 'launched_at', 'id'], 'products_status_launched_at_id_index');
            // GET /me/products and GET /users/{handle}/products orderings.
            $table->index(['maker_id', 'created_at', 'id'], 'products_maker_id_created_at_id_index');
            $table->index(['maker_id', 'launched_at', 'id'], 'products_maker_id_launched_at_id_index');
        });

        // Sub-project 6 owns the text-search configuration; keep a plain nullable column until then (D8).
        DB::statement('ALTER TABLE products ADD COLUMN search_vector tsvector');

        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_slug_format
                CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND char_length(slug) BETWEEN 3 AND 80)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_launch_date_state
                CHECK (launch_date IS NULL OR status IN ('scheduled', 'live', 'taken_down'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_scheduled_needs_date
                CHECK (status <> 'scheduled' OR launch_date IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
