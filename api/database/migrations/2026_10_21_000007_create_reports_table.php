<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporter_id')->constrained('users');
            // Polymorphic target with no foreign key: the actions validate it.
            $table->string('reportable_type', 32);
            $table->char('reportable_id', 26);
            $table->enum('reason', ReportReason::values());
            $table->string('details', 1000)->nullable();
            $table->enum('status', ReportStatus::values())->default(ReportStatus::Open->value);
            // Null `handled_by` on a closed report means the system closed it (the target was deleted by its author).
            $table->foreignUlid('handled_by')->nullable()->constrained('users');
            $table->timestampTz('handled_at', 6)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->nullable();

            $table->index(['status', 'created_at']);
            $table->index(['reportable_type', 'reportable_id']);
            $table->index(['reporter_id', 'status']);
        });

        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_type_check CHECK (reportable_type IN ('product', 'comment'))");
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_open_matches_handled CHECK ((status = 'open') = (handled_at IS NULL))");
        // One OPEN report per reporter per target; a closed one never blocks a later report (the action refuses after a dismissal).
        DB::statement("CREATE UNIQUE INDEX reports_open_unique ON reports (reporter_id, reportable_type, reportable_id) WHERE status = 'open'");
        // The history list: closed reports, newest first, keyset (handled_at, id).
        DB::statement("CREATE INDEX reports_history_index ON reports (handled_at DESC, id DESC) WHERE status <> 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
