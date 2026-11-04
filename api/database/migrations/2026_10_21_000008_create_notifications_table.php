<?php

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The only recipient is a member, so a real foreign key replaces Laravel's polymorphic notifiable columns. Accounts are
            // anonymised rather than deleted, and DeleteAccount removes the rows itself; the cascade covers a hard delete.
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->jsonb('data');
            // Idempotency: a retried listener, the reconcile sweep and a duplicate event all derive the same key.
            $table->string('dedupe_key', 191);
            $table->timestampTz('read_at', 6)->nullable();
            // created_at sits in the keyset cursor of the list, so it keeps microseconds.
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
        });

        $types = implode(', ', array_map(fn (string $type) => "'{$type}'", NotificationType::values()));
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_valid CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_data_object CHECK (jsonb_typeof(data) = 'object')");
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_read_after_created CHECK (read_at IS NULL OR read_at >= created_at)');

        // Every keyset ordering has its index (Blueprint cannot express DESC or WHERE). The partial one serves the capped
        // unread count and the unread=1 list; the other serves the full list; the third serves the retention purge.
        DB::statement('CREATE INDEX notifications_user_index ON notifications (user_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX notifications_unread_index ON notifications (user_id, created_at DESC, id DESC) WHERE read_at IS NULL');
        DB::statement('CREATE INDEX notifications_created_at_index ON notifications (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
