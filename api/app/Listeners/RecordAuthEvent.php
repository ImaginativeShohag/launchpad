<?php

namespace App\Listeners;

use App\Enums\AuthEventType;
use App\Models\User;
use App\Support\AuthLog;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Laravel discovers the handle* methods from their type-hints; no registration needed.
 * Bookkeeping must never break sign-in or sign-up, so failures are reported, not thrown.
 */
final class RecordAuthEvent
{
    public function handleRegistered(Registered $event): void
    {
        $this->safely(fn () => AuthLog::record(AuthEventType::Signup, $this->id($event->user)));
    }

    public function handleLogin(Login $event): void
    {
        $this->safely(fn () => AuthLog::record(AuthEventType::LoginSucceeded, $this->id($event->user)));

        if ($event->user instanceof User) {
            $user = $event->user;
            $this->safely(fn () => $user->forceFill(['last_login_at' => now()])->saveQuietly());
        }
    }

    public function handleLogout(Logout $event): void
    {
        $this->safely(fn () => AuthLog::record(AuthEventType::Logout, $this->id($event->user)));
    }

    public function handleVerified(Verified $event): void
    {
        $this->safely(fn () => AuthLog::record(AuthEventType::EmailVerified, $this->id($event->user)));
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->safely(fn () => AuthLog::record(AuthEventType::PasswordReset, $this->id($event->user)));
    }

    /**
     * Runs bookkeeping in its own transaction (a savepoint when nested) so a failed statement
     * cannot poison the caller's Postgres transaction, and reports instead of throwing.
     */
    /** @param Closure(): mixed $work */
    private function safely(Closure $work): void
    {
        try {
            DB::transaction($work);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function id(mixed $user): ?string
    {
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        return $id === null ? null : (string) $id;
    }
}
