<?php

use App\Enums\EmailType;
use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The values the two CHECKs held before `export_ready` joined them. */
    private const OLD_NOTIFICATION_TYPES = ['review_approved', 'review_rejected', 'review_changes_requested', 'launch_reminder', 'product_live', 'new_comment'];

    private const OLD_PREFERENCE_TYPES = ['review_outcome', 'launch_reminder', 'product_live', 'new_comment', 'daily_top5', 'weekly_best'];

    public function up(): void
    {
        // `email_messages_type_valid` already lists every EmailType (export_ready included); only these two were narrower.
        $this->recreate('notifications', 'notifications_type_valid', 'type', NotificationType::values());
        $this->recreate('email_preferences', 'email_preferences_type_optional', 'type', array_map(fn (EmailType $type) => $type->value, EmailType::preferenceTypes()));
    }

    public function down(): void
    {
        $this->recreate('notifications', 'notifications_type_valid', 'type', self::OLD_NOTIFICATION_TYPES);
        $this->recreate('email_preferences', 'email_preferences_type_optional', 'type', self::OLD_PREFERENCE_TYPES);
    }

    /** @param  list<string>  $values */
    private function recreate(string $table, string $constraint, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$list}))");
    }
};
