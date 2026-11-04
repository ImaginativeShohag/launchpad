<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CancelEmailChange
{
    /** Returns whether a pending change was actually cleared. */
    public function handle(mixed $userId, mixed $hash): bool
    {
        abort_unless(is_string($userId) && Str::isUlid($userId) && is_string($hash), 422, ConfirmEmailChange::INVALID);

        return DB::transaction(function () use ($userId, $hash): bool {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            // Nothing pending (already cancelled or confirmed, or no such member): harmless.
            if ($user === null || $user->pending_email === null) {
                return false;
            }

            abort_unless(hash_equals(sha1($user->pending_email), $hash), 422, ConfirmEmailChange::INVALID);

            $user->forceFill(['pending_email' => null, 'pending_email_expires_at' => null])->save();

            return true;
        });
    }
}
