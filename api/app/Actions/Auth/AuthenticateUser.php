<?php

namespace App\Actions\Auth;

use App\Enums\AuthEventType;
use App\Models\User;
use App\Support\AuthLog;
use App\Support\PasswordCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticateUser
{
    /**
     * Wrong password, unknown email, suspended, deleted and password-less accounts all
     * fail identically and always cost one hash check, so neither the message nor the
     * timing reveals which case it was.
     */
    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', User::normaliseEmail($email))->first();

        $usable = $user !== null
            && $user->hasPassword()
            && ! $user->isSuspended()
            && ! $user->isDeleted();

        $valid = PasswordCheck::verify($password, $usable ? (string) $user->password : $this->dummyHash());

        if ($user === null || ! $usable || ! $valid) {
            AuthLog::tryRecord(AuthEventType::LoginFailed, $user?->getKey());

            throw ValidationException::withMessages(['email' => [trans('auth.failed')]]);
        }

        $this->upgradeHash($user, $password);

        return $user;
    }

    /** Re-hash after a successful check when the stored hash uses outdated parameters (best effort). */
    private function upgradeHash(User $user, string $password): void
    {
        try {
            if (Hash::needsRehash((string) $user->password)) {
                DB::transaction(fn () => $user->forceFill(['password' => $password])->saveQuietly());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }
}
