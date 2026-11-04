<?php

namespace App\Actions\Auth;

use App\Enums\AuthEventType;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfirmEmailChange
{
    public const INVALID = 'This link is invalid or has expired.';

    public function __construct(private readonly SignOutEverywhere $signOut) {}

    /** Every reason a link is unusable gives the same 422, so nothing is revealed about the account. */
    public function handle(mixed $userId, mixed $hash): void
    {
        abort_unless(is_string($userId) && Str::isUlid($userId) && is_string($hash), 422, self::INVALID);

        DB::transaction(function () use ($userId, $hash): void {
            // The row lock serialises concurrent confirms and a confirm racing a newer request;
            // everything below is checked against the locked, fresh row.
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            $pending = $user?->pending_email;

            abort_if(
                $user === null
                    || $user->isDeleted()
                    || $user->isSuspended()
                    || $pending === null
                    || $user->pending_email_expires_at === null
                    || $user->pending_email_expires_at->isPast()
                    || ! hash_equals(sha1($pending), $hash),
                422,
                self::INVALID,
            );

            $taken = fn (): bool => User::query()->where('email', $pending)->whereKeyNot($user->getKey())->exists();
            abort_if($taken(), 409, 'That email address is already in use.');

            $oldEmail = $user->email;

            try {
                // Own savepoint: a unique violation must not poison the surrounding transaction.
                DB::transaction(fn () => $user->forceFill([
                    'email' => $pending,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_expires_at' => null,
                ])->save());
            } catch (UniqueConstraintViolationException $e) {
                // Someone claimed the address between the check and the write. Decide from the data,
                // never from the message; any other unique violation is a real error.
                abort_if($taken(), 409, 'That email address is already in use.');

                throw $e;
            }

            // Reset tokens were issued for the old address; they must not outlive it.
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->whereIn('email', [$oldEmail, $pending])->delete();

            $this->signOut->handle($user);
            AuthLog::tryRecord(AuthEventType::EmailChanged, (string) $user->getKey());
        });
    }
}
