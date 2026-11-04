<?php

namespace App\Actions\Auth;

use App\Enums\AuthEventType;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Support\Facades\DB;

class IssueAccessToken
{
    public function handle(User $user, string $deviceName): string
    {
        // The token row and last_login_at are required: if either write fails the request fails and
        // the transaction rolls the token back, so no live token exists that the client never received.
        $token = DB::transaction(function () use ($user, $deviceName) {
            $token = $user->createToken(
                $deviceName,
                ['*'],
                now()->addDays((int) config('launchpad.token_ttl_days')),
            );
            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            return $token;
        });

        // Bookkeeping is best effort. login_succeeded is recorded here because the token path never
        // fires Laravel's Login event (which records it for the web session).
        AuthLog::tryRecord(AuthEventType::TokenCreated, $user->getKey());
        AuthLog::tryRecord(AuthEventType::LoginSucceeded, $user->getKey());

        return $token->plainTextToken;
    }
}
