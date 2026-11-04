<?php

namespace App\Actions\Email\Concerns;

use App\Enums\EmailType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

trait UpsertsEmailPreference
{
    /**
     * `INSERT ... ON CONFLICT DO UPDATE` that writes nothing when the stored value is already the wanted one, so an
     * unchanged value keeps its `updated_at`. Turning a type back on writes `enabled = true`; the row is never deleted.
     *
     * @return bool true when a row was inserted or changed
     */
    private function upsertPreference(string $userId, EmailType $type, bool $enabled, string $now): bool
    {
        // The table CHECK refuses a mandatory type too; this fails earlier and with a clearer error.
        if (! $type->isOptional()) {
            throw new InvalidArgumentException("{$type->value} cannot be switched off.");
        }

        return DB::affectingStatement(
            'INSERT INTO email_preferences (user_id, type, enabled, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, type) DO UPDATE
                SET enabled = EXCLUDED.enabled, updated_at = EXCLUDED.updated_at
                WHERE email_preferences.enabled IS DISTINCT FROM EXCLUDED.enabled',
            [$userId, $type->value, $enabled, $now],
        ) > 0;
    }
}
