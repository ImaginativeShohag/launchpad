<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SignOutEverywhere
{
    /**
     * Revokes every browser session and device token, except the ones named. It also rotates the
     * remember-me token, because a stolen "remember me" cookie would otherwise survive. For a password
     * change, which keeps the current session, this signs out remember-me cookies on other devices only
     * (the current session is unaffected); that is accepted.
     */
    public function handle(User $user, ?string $exceptSessionId = null, int|string|null $exceptTokenId = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $user->tokens()
            ->when($exceptTokenId, fn ($query) => $query->where('id', '!=', $exceptTokenId))
            ->delete();
    }
}
