<?php

namespace App\Actions\Notification;

use App\Actions\Notification\Concerns\AnnouncesUnreadCount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only code that writes an event-driven bell row and its outbox mail. Idempotent: the same (member, dedupe key) and
 * the same outbox key are written once, however often the event, the queue retry or the reconcile sweep calls it. Both
 * rows commit together or not at all.
 *
 * Lock order: the recipient's user row `FOR SHARE` is the last lock and the only one; this action never holds a product
 * or comment row and must not be called inside a transaction that does. The share lock makes a concurrent
 * `DeleteAccount` (which holds the row `FOR UPDATE`) finish first, so nothing is written for a member being erased.
 */
class RecordNotification
{
    use AnnouncesUnreadCount;

    private const DATA_KEYS = ['message_key', 'product_id', 'review_id', 'comment_id', 'params', 'href'];

    /** @return bool true when a NEW in-site row was inserted */
    public function handle(NotificationDraft $draft): bool
    {
        $this->assertPrivacyShape($draft);
        $this->assertEmailPayload($draft->emailPayload);

        $inserted = DB::transaction(function () use ($draft): bool {
            /** @var object{id: string, email: string, email_verified_at: ?string, deleted_at: ?string, suspended_at: ?string}|null $recipient */
            $recipient = DB::table('users')->where('id', $draft->userId)->sharedLock()->first(['id', 'email', 'email_verified_at', 'deleted_at', 'suspended_at']);

            // Unknown, deleted and suspended recipients get nothing. An unverified member does: the bell is always on.
            if ($recipient === null || $recipient->deleted_at !== null || $recipient->suspended_at !== null) {
                return false;
            }

            $now = Carbon::now('UTC')->format('Y-m-d H:i:s.uP');
            $rows = DB::select(
                'INSERT INTO notifications (id, user_id, type, data, dedupe_key, read_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?::jsonb, ?, NULL, ?, ?)
                 ON CONFLICT (user_id, dedupe_key) DO NOTHING
                 RETURNING id',
                [
                    (string) Str::uuid7(), $draft->userId, $draft->type->value, json_encode($draft->data, JSON_THROW_ON_ERROR),
                    $draft->dedupeKey, $now, $now,
                ],
            );

            // Repair rule: also when the bell row already existed, the outbox row is attempted (its own ON CONFLICT makes that
            // harmless), so the reconcile sweep can restore a lost outbox row for an old notification.
            $this->enqueueEmail($draft, $recipient, $now);

            return $rows !== [];
        });

        if ($inserted) {
            $this->announceUnreadCount($draft->userId);
        }

        return $inserted;
    }

    /**
     * `data` is returned to the client and read by phone apps, so its shape is enforced here, before any query: ids, a
     * message key, scalar params and an app-relative href. Never prose, a note, a body or an absolute URL.
     *
     * @throws InvalidArgumentException
     */
    private function assertPrivacyShape(NotificationDraft $draft): void
    {
        $data = $draft->data;

        if (array_diff(array_keys($data), self::DATA_KEYS) !== []) {
            throw new InvalidArgumentException('Notification data has a key outside the allowed set.');
        }

        if (($data['message_key'] ?? null) !== $draft->type->messageKey()) {
            throw new InvalidArgumentException('Notification message_key must match the type.');
        }

        foreach (['product_id', 'review_id', 'comment_id'] as $key) {
            if (array_key_exists($key, $data) && ! is_string($data[$key])) {
                throw new InvalidArgumentException("Notification {$key} must be a string id.");
            }
        }

        $href = $data['href'] ?? null;
        if ($href !== null && (! is_string($href) || preg_match('~\A/[^/\\\s]~', $href) !== 1)) {
            throw new InvalidArgumentException('Notification href must be an app-relative path.');
        }

        $params = $data['params'] ?? [];
        if (! is_array($params)) {
            throw new InvalidArgumentException('Notification params must be an array.');
        }
        foreach ($params as $key => $value) {
            if (! is_string($key) || ! (is_scalar($value) || $value === null)) {
                throw new InvalidArgumentException('Notification params may hold scalar values only.');
            }
        }
    }

    /**
     * The mail, in the same transaction as the bell row. The preference and verified checks here only save a row; the
     * rule that counts is the send-time check in `SendEmailMessage`.
     *
     * @param  object{email: string, email_verified_at: ?string}  $recipient
     */
    private function enqueueEmail(NotificationDraft $draft, object $recipient, string $now): void
    {
        if ($draft->emailKey === null || $recipient->email_verified_at === null) {
            return;
        }

        $emailType = $draft->type->emailType();
        $optedOut = $emailType->isOptional() && DB::table('email_preferences')
            ->where('user_id', $draft->userId)->where('type', $emailType->value)->where('enabled', false)->exists();

        if ($optedOut) {
            return;
        }

        try {
            DB::insert(
                'INSERT INTO email_messages (id, user_id, type, idempotency_key, to_email, payload, status, attempts, next_attempt_at, last_error, provider_message_id, sent_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, \'queued\', 0, ?, NULL, NULL, NULL, ?, ?)
             ON CONFLICT (idempotency_key) DO NOTHING',
                [
                    strtolower((string) Str::ulid()), $draft->userId, $emailType->value, $draft->emailKey, $recipient->email,
                    json_encode($draft->emailPayload, JSON_THROW_ON_ERROR), $now, $now, $now,
                ],
            );
        } catch (QueryException $e) {
            // The driver message and bindings carry the recipient's address: rethrow the SQLSTATE and the table only.
            throw new RuntimeException('Writing email_messages failed (SQLSTATE '.($e->errorInfo[0] ?? 'unknown').').');
        }
    }

    /**
     * The outbox payload is stored for the retention window: ids and small scalars (and the list of digest product ids)
     * only, never an address, a body or a note.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    private function assertEmailPayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $scalar = is_string($value) || is_int($value) || is_bool($value) || $value === null;
            $ids = is_array($value) && array_is_list($value) && array_filter($value, fn ($item) => ! is_string($item)) === [];

            if (! is_string($key) || ! ($scalar || $ids) || (is_string($value) && strlen($value) > 191)) {
                throw new InvalidArgumentException('The email payload may hold ids and small scalars only.');
            }
        }
    }
}
