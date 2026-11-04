<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;

class ConfirmEmail
{
    /**
     * The write is conditional, so concurrent calls fire Verified once and a
     * verification in flight never lands on an address that changed meanwhile.
     */
    public function handle(User $user): void
    {
        $updated = User::query()->whereKey($user->getKey())
            ->whereNull('email_verified_at')
            ->where('email', $user->email)
            ->update(['email_verified_at' => now()]);

        if ($updated === 1) {
            $user->refresh();
            event(new Verified($user));
        }
    }
}
