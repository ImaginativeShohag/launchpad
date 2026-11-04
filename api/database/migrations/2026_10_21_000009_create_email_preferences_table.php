<?php

use App\Enums\EmailType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_preferences', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->boolean('enabled');
            $table->timestampTz('updated_at', 6);

            $table->primary(['user_id', 'type']);
        });

        // Only the optional types can be stored, so a row can never switch off a mandatory mail (verify, reset, email change).
        $types = implode(', ', array_map(fn (EmailType $type) => "'{$type->value}'", EmailType::preferenceTypes()));
        DB::statement("ALTER TABLE email_preferences ADD CONSTRAINT email_preferences_type_optional CHECK (type IN ({$types}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_preferences');
    }
};
