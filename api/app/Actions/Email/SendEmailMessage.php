<?php

namespace App\Actions\Email;

use App\Enums\EmailType;
use App\Mail\MailFor;
use App\Mail\MailTargetGone;
use App\Mail\UnrenderableMail;
use App\Models\EmailMessage;
use App\Support\Mail\ErrorSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Sends one claimed outbox row. Three steps, and the network call sits between two short row-locked transactions, never
 * inside one (the lease keeps the row ours meanwhile):
 *
 *  1. lock the row, confirm it is still our claim, apply the send-time rule ("off means we must not send"), refresh the
 *     address;
 *  2. render and send, outside any transaction;
 *  3. lock the row again, confirm it is still our claim, record the result.
 *
 * Delivery is at least once: a crash after the provider accepted but before step 3 sends again once the lease expires,
 * with the same `Message-ID` (the row id) so mail clients can collapse the copy.
 *
 * Failures are classified by exception CLASS and structured status code, never by the message text. They are reported
 * and recorded on the row, not rethrown. Lock order: this row only; the user is read with plain selects.
 */
class SendEmailMessage
{
    public function handle(string $id, int $attempts, CarbonImmutable $now): SendResult
    {
        $row = DB::transaction(function () use ($id, $attempts, $now): object {
            $row = DB::table('email_messages')->where('id', $id)->lockForUpdate()->first();

            if ($row === null || $row->status !== 'sending' || (int) $row->attempts !== $attempts) {
                return new SendResult('skipped');
            }

            $user = DB::table('users')->where('id', $row->user_id)->first(['id', 'email', 'email_verified_at', 'deleted_at', 'suspended_at']);
            $reason = $this->suppressionReason($row, $user);

            if ($reason !== null || $user === null) {
                $this->suppress($id, $reason ?? 'user_missing', $now);

                return new SendResult('suppressed');
            }

            // The current address, not the one from enqueue time (the member may have changed it since).
            if ($row->to_email !== $user->email) {
                DB::table('email_messages')->where('id', $id)->update(['to_email' => $user->email]);
                $row->to_email = $user->email;
            }

            return $row;
        });

        if ($row instanceof SendResult) {
            return $row;
        }

        $message = (new EmailMessage)->newFromBuilder((array) $row);

        try {
            $mailable = MailFor::make($message);
        } catch (MailTargetGone) {
            return DB::transaction(fn () => $this->ifOurClaim($id, $attempts, function () use ($id, $now): SendResult {
                $this->suppress($id, 'target_gone', $now);

                return new SendResult('suppressed');
            }));
        } catch (Throwable $e) {
            // UnrenderableMail and any other render fault: a bug, not a transient condition.
            report($e);

            return $this->fail($id, $attempts, $e, permanent: true);
        }

        try {
            $domain = substr((string) strrchr((string) config('mail.from.address'), '@'), 1) ?: 'localhost';
            $sent = Mail::mailer()->to($row->to_email)->send(
                $mailable->withSymfonyMessage(fn (Email $email) => $email->getHeaders()->addIdHeader('Message-ID', $id.'@'.$domain)),
            );
            $providerId = $sent?->getMessageId();
        } catch (Throwable $e) {
            // A transport message echoes recipients and its trace is noise: log the redacted summary only, never report() it.
            Log::warning('outbox.send_failed', ['id' => $id, 'attempt' => $attempts, 'error' => ErrorSummary::of($e)]);

            return $this->fail($id, $attempts, $e, permanent: $this->isPermanent($e));
        }

        return $this->recordSent($id, $attempts, $providerId);
    }

    /** The reason a row must not be sent right now, or null. Optional types honour the member's preference. */
    private function suppressionReason(object $row, ?object $user): ?string
    {
        /** @var object{type: string, user_id: string} $row */
        /** @var object{deleted_at: ?string, suspended_at: ?string, email_verified_at: ?string}|null $user */
        return match (true) {
            $user === null => 'user_missing',
            $user->deleted_at !== null => 'user_deleted',
            $user->suspended_at !== null => 'user_suspended',
            $user->email_verified_at === null => 'email_unverified',
            EmailType::from($row->type)->isOptional() && DB::table('email_preferences')
                ->where('user_id', $row->user_id)->where('type', $row->type)->where('enabled', false)->exists() => 'opted_out',
            default => null,
        };
    }

    /** No attempt was made, so none is counted: the claim's increment is taken off again. */
    private function suppress(string $id, string $reason, CarbonImmutable $now): void
    {
        DB::table('email_messages')->where('id', $id)->update([
            'status' => 'suppressed', 'next_attempt_at' => null, 'attempts' => DB::raw('greatest(attempts - 1, 0)'),
            'last_error' => $reason, 'updated_at' => $now->utc()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function recordSent(string $id, int $attempts, ?string $providerId): SendResult
    {
        return DB::transaction(fn () => $this->ifOurClaim($id, $attempts, function () use ($id, $providerId): SendResult {
            $moment = Date::now()->utc()->format('Y-m-d H:i:s.uP');
            DB::table('email_messages')->where('id', $id)->update([
                'status' => 'sent', 'sent_at' => $moment, 'next_attempt_at' => null, 'provider_message_id' => $providerId === null ? null : mb_substr($providerId, 0, 255),
                'last_error' => null, 'updated_at' => $moment,
            ]);

            return new SendResult('sent');
        }));
    }

    /** Transient: back to `queued` with the next backoff step, or `failed` at the last attempt. Permanent: `failed` now. */
    private function fail(string $id, int $attempts, Throwable $e, bool $permanent): SendResult
    {
        return DB::transaction(fn () => $this->ifOurClaim($id, $attempts, function () use ($id, $attempts, $e, $permanent): SendResult {
            $now = Date::now();
            $moment = $now->utc()->format('Y-m-d H:i:s.uP');
            $error = ErrorSummary::of($e);
            $max = (int) config('launchpad.outbox.max_attempts');
            $waits = (array) config('launchpad.outbox.backoff_minutes');

            if ($permanent || $attempts >= $max) {
                DB::table('email_messages')->where('id', $id)->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error' => $error, 'updated_at' => $moment]);

                return new SendResult('failed', transientFailure: ! $permanent);
            }

            $wait = (int) ($waits[$attempts - 1] ?? end($waits));
            DB::table('email_messages')->where('id', $id)->update([
                'status' => 'queued', 'next_attempt_at' => $now->copy()->addMinutes($wait)->utc()->format('Y-m-d H:i:s.uP'),
                'last_error' => $error, 'updated_at' => $moment,
            ]);

            return new SendResult('retried', transientFailure: true);
        }));
    }

    /**
     * Runs $record under the row lock when the row is still the claim this worker holds (`sending` with the same
     * `attempts`); otherwise the lease expired, the account was scrubbed or another worker took it, and nothing is written.
     *
     * @param  callable(): SendResult  $record
     */
    private function ifOurClaim(string $id, int $attempts, callable $record): SendResult
    {
        $row = DB::table('email_messages')->where('id', $id)->lockForUpdate()->first(['status', 'attempts']);

        if ($row === null || $row->status !== 'sending' || (int) $row->attempts !== $attempts) {
            return new SendResult('skipped');
        }

        return $record();
    }

    /** Permanent only on a class or structured code that says so: a malformed address/message, an HTTP provider 4xx other than 408/429, or an SMTP reply code in 500-599. */
    private function isPermanent(Throwable $e): bool
    {
        if ($e instanceof RfcComplianceException || $e instanceof UnrenderableMail) {
            return true;
        }

        if ($e instanceof HttpTransportException) {
            try {
                // The HTTP client contracts package is not installed (no HTTP mailer is), so its interface is unknown to the analyser.
                $status = $e->getResponse()->getStatusCode(); // @phpstan-ignore class.notFound
            } catch (Throwable) {
                return false;
            }

            return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
        }

        // SMTP: the transport passes the server's reply code as the exception code (0 when there was none, e.g. a dropped
        // connection). A 5xx reply is a hard bounce; 4xx and 0 are transient. The message text is never parsed.
        if ($e instanceof TransportException) {
            return $e->getCode() >= 500 && $e->getCode() <= 599;
        }

        return false;
    }
}
