<?php

namespace App\Actions\Email;

use App\Actions\Email\Concerns\UpsertsEmailPreference;
use App\Enums\EmailType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one-click unsubscribe. The signature proves the member id, so the caller answers the same for every outcome
 * (no existence oracle): this returns whether something changed, the response never depends on it.
 */
class UnsubscribeFromEmail
{
    use UpsertsEmailPreference;

    /** @return bool true when it switched something off; false = already off, or no such live member */
    public function handle(string $userId, EmailType $type): bool
    {
        return DB::transaction(function () use ($userId, $type): bool {
            // FOR SHARE: a concurrent DeleteAccount (user row FOR UPDATE) finishes first, so no preference row is written for an erased member.
            $user = DB::table('users')->where('id', $userId)->sharedLock()->first(['id', 'deleted_at']);

            if ($user === null || $user->deleted_at !== null) {
                return false;
            }

            return $this->upsertPreference($userId, $type, false, Carbon::now('UTC')->format('Y-m-d H:i:s.uP'));
        });
    }
}
