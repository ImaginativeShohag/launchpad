<?php

namespace App\Jobs;

use App\Enums\AuthEventType;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Password as PasswordBroker;

/**
 * Everything account-dependent about "forgot password" lives here, off the request path, so the
 * HTTP answer costs the same for every email. The payload holds an email address, hence encrypted.
 *
 * Transient failures (mail provider, database) bubble up so the worker retries; nothing may go
 * missing. Under the sync driver such a failure would reach the client as a 500, which is one more
 * reason production must not use it.
 */
class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly string $email) {}

    /**
     * The first wait must exceed the broker's 60 s throttle: a retry inside it would answer
     * RESET_THROTTLED and look like success although no mail was sent. The tail matches the queued notifications
     * (about 2.9 hours of retries in all).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [65, 120, 300, 900, 1800, 3600, 3600];
    }

    public function handle(): void
    {
        $user = User::query()->where('email', $this->email)->first();

        // Suspended and deleted accounts cannot reset, so they get no mail. RESET_LINK_SENT and
        // RESET_THROTTLED both count as done; the other outcomes are deliberately not told apart.
        if ($user !== null && ! $user->isSuspended() && ! $user->isDeleted()) {
            PasswordBroker::sendResetLink(['email' => $this->email]);
        }

        // Last, so a retried job does not log twice. Best effort.
        AuthLog::tryRecord(AuthEventType::PasswordResetRequested, $user?->getKey());
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
