<?php

namespace App\Actions\Auth;

use App\Enums\AuthEventType;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use App\Support\AuthLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RequestEmailChange
{
    /** $newEmail must already be normalised (User::normaliseEmail). */
    public function handle(User $user, string $newEmail): void
    {
        $expires = now()->addMinutes((int) config('launchpad.email_change_ttl_minutes'));

        DB::transaction(function () use ($user, $newEmail, $expires): void {
            // Re-read under a row lock so a confirm racing this request is serialised with it,
            // and the write never starts from a stale model.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['pending_email' => $newEmail, 'pending_email_expires_at' => $expires])->save();

            // Queued after commit: nothing is mailed if this transaction rolls back.
            Notification::route('mail', $newEmail)
                ->notify(new ConfirmEmailChangeNotification((string) $user->getKey(), $newEmail, $expires));
            $user->notify(new EmailChangeRequestedNotification($newEmail, $expires));
        });

        AuthLog::tryRecord(AuthEventType::EmailChangeRequested, (string) $user->getKey());
    }
}
