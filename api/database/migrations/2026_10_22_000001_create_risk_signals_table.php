<?php

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_signals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 24);
            // Plain foreign keys, no cascade: a signal is history. A product-level signal has no member.
            $table->foreignUlid('user_id')->nullable()->constrained('users');
            $table->foreignUlid('product_id')->nullable()->constrained('products');
            // Frozen at detection (after any discount); a later setting change never rewrites it.
            $table->smallInteger('weight');
            // Evidence: ids and counts only, never a hash, an address or an e-mail.
            $table->jsonb('details')->default('{}');
            $table->string('dedupe_key', 160)->unique();
            $table->timestampTz('detected_at', 0);
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at', 0)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->string('review_note', 255)->nullable();

            $table->index(['user_id', 'detected_at']);
            $table->index(['product_id', 'detected_at']);
        });

        $kinds = $this->quoted(RiskSignalKind::values());
        $outcomes = $this->quoted(RiskOutcome::values());

        DB::statement("ALTER TABLE risk_signals ADD CONSTRAINT risk_signals_kind_check CHECK (kind IN ({$kinds}))");
        DB::statement("ALTER TABLE risk_signals ADD CONSTRAINT risk_signals_outcome_check CHECK (outcome IS NULL OR outcome IN ({$outcomes}))");
        DB::statement('ALTER TABLE risk_signals ADD CONSTRAINT risk_signals_weight_range CHECK (weight BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE risk_signals ADD CONSTRAINT risk_signals_subject_present CHECK (user_id IS NOT NULL OR product_id IS NOT NULL)');
        DB::statement('ALTER TABLE risk_signals ADD CONSTRAINT risk_signals_review_complete CHECK ((reviewed_by IS NULL) = (reviewed_at IS NULL) AND (reviewed_at IS NULL) = (outcome IS NULL))');

        // The open queue, worst first; the keyset ends in id DESC like every ordering of this table.
        DB::statement('CREATE INDEX risk_signals_open_queue_index ON risk_signals (weight DESC, detected_at DESC, id DESC) WHERE reviewed_at IS NULL');
        // The retention purge and the coordinated-votes candidates read unreviewed rows by age.
        DB::statement('CREATE INDEX risk_signals_unreviewed_detected_index ON risk_signals (detected_at) WHERE reviewed_at IS NULL');
        // "Recently reviewed first" list; also serves the purge of reviewed rows.
        DB::statement('CREATE INDEX risk_signals_reviewed_index ON risk_signals (reviewed_at DESC, id DESC) WHERE reviewed_at IS NOT NULL');
        // The all-signals list, newest first.
        DB::statement('CREATE INDEX risk_signals_detected_index ON risk_signals (detected_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_signals');
    }

    /** @param  list<string>  $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
    }
};
