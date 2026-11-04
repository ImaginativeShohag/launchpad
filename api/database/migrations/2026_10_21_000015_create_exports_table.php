<?php

use App\Enums\ExportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One dashboard export (a zip of four CSV files) of a product, asked for by its maker. The file lives on the private
        // `exports` disk at {product_id}/{export_id}.zip; `file_path` holds that key and is never sent to a client.
        Schema::create('exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Plain foreign keys (no cascade): accounts are anonymised and products soft-deleted, never hard-deleted.
            $table->foreignUlid('user_id')->constrained();
            $table->foreignUlid('product_id')->constrained();
            $table->string('status', 16)->default('queued');
            $table->string('file_path', 255)->nullable();
            $table->integer('file_size')->nullable();
            $table->jsonb('row_counts')->nullable();
            // A short code only (generation_failed, too_large, stalled), never an exception message.
            $table->string('error', 32)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        $statuses = implode(', ', array_map(fn (string $status) => "'{$status}'", ExportStatus::values()));
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_status_valid CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_ready_has_file CHECK (status <> 'ready' OR (file_path IS NOT NULL AND file_size IS NOT NULL AND completed_at IS NOT NULL AND expires_at IS NOT NULL AND row_counts IS NOT NULL))");
        DB::statement('ALTER TABLE exports ADD CONSTRAINT exports_file_size_range CHECK (file_size IS NULL OR file_size BETWEEN 0 AND 5242880)');
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_error_only_when_failed CHECK (error IS NULL OR status = 'failed')");

        DB::statement('CREATE INDEX exports_user_id_created_at_index ON exports (user_id, created_at)');
        // At most one export per product that is queued, processing or ready. RequestExport's lookups repeat this predicate.
        DB::statement("CREATE UNIQUE INDEX exports_one_active_per_product ON exports (product_id) WHERE status IN ('queued','processing','ready')");
        DB::statement('CREATE INDEX exports_status_expires_at_index ON exports (status, expires_at)');
        // The maker's list: latest five of a product, newest first.
        DB::statement('CREATE INDEX exports_product_created_index ON exports (product_id, created_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
