<?php

use App\Enums\EmailStatus;
use App\Enums\EmailType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // A plain foreign key on purpose: accounts are anonymised, never hard-deleted, and DeleteAccount scrubs the rows itself.
            $table->foreignUlid('user_id')->constrained();
            $table->string('type', 32);
            $table->string('idempotency_key', 191)->unique('email_messages_idempotency_key_unique');
            // Personal data: purged with the row (retention) and replaced by the anonymised address on account deletion.
            $table->string('to_email', 255);
            $table->jsonb('payload');
            $table->string('status', 16)->default('queued');
            $table->smallInteger('attempts')->default(0);
            // The due time of a queued row and the lease end of a sending one; NULL once the row is terminal.
            $table->timestampTz('next_attempt_at', 6)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        $types = implode(', ', array_map(fn (string $type) => "'{$type}'", EmailType::values()));
        $statuses = implode(', ', array_map(fn (string $status) => "'{$status}'", EmailStatus::values()));
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_type_valid CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_status_valid CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE email_messages ADD CONSTRAINT email_messages_attempts_valid CHECK (attempts >= 0)');
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_payload_object CHECK (jsonb_typeof(payload) = 'object')");
        // A due time exists exactly while the row is waiting or being sent.
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_due_state CHECK ((status IN ('queued', 'sending')) = (next_attempt_at IS NOT NULL))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_sent_at_state CHECK ((status = 'sent') = (sent_at IS NOT NULL))");

        // The claim query, the user lookup of the scrub/suppression, and the retention purge (its WHERE repeats this predicate text).
        // The claim orders by (next_attempt_at, id) across both states, so those are the key columns (a leading `status` would
        // force a sort of the whole due backlog); the partial predicate already restricts the rows to the two waiting states.
        DB::statement("CREATE INDEX email_messages_due_index ON email_messages (next_attempt_at, id) WHERE status IN ('queued', 'sending')");
        DB::statement('CREATE INDEX email_messages_user_type_index ON email_messages (user_id, type)');
        DB::statement("CREATE INDEX email_messages_terminal_created_index ON email_messages (created_at) WHERE status IN ('sent', 'failed', 'suppressed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
