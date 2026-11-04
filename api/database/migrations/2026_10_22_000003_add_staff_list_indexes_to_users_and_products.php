<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two staff lists of the web app. Members: newest first over everyone who has not deleted their account (the
     * predicate is the list's own default filter). Products: newest change first over everything that has left the
     * draft stage and is not soft-deleted (the staff list never shows drafts); `updated_at DESC, id DESC` so the
     * keyset cursor runs in one direction.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS users_created_at_id_index ON users (created_at DESC, id DESC) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX IF NOT EXISTS products_staff_list_index ON products (updated_at DESC, id DESC) WHERE status <> 'draft' AND deleted_at IS NULL");
        // The same list with a status filter.
        DB::statement('CREATE INDEX IF NOT EXISTS products_staff_status_index ON products (status, updated_at DESC, id DESC) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_created_at_id_index');
        DB::statement('DROP INDEX IF EXISTS products_staff_list_index');
        DB::statement('DROP INDEX IF EXISTS products_staff_status_index');
    }
};
