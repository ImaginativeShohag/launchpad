<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unique(['user_id', 'provider'], 'social_accounts_user_id_provider_unique');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE social_accounts DROP CONSTRAINT IF EXISTS social_accounts_user_id_provider_unique');
    }
};
