<?php

use App\Actions\Notification\NotificationDraft;
use App\Enums\EmailType;
use App\Enums\NotificationType;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** An in-memory outbox row (never saved) for MailFor::make(). */
function outboxMessage(EmailType $type, array $payload, ?User $user = null): EmailMessage
{
    $user ??= User::factory()->create();

    return EmailMessage::factory()->forUser($user)->ofType($type, $payload)->make();
}

/** A saved row, queued and due, for the sender tests. */
function outboxQueued(EmailType $type, array $payload, ?User $user = null, array $attributes = []): EmailMessage
{
    $user ??= User::factory()->create();

    return EmailMessage::factory()->forUser($user)->ofType($type, $payload)->create($attributes);
}

/** The payload a review_outcome row carries for $product. */
function outboxReviewPayload(string $productId, string $decision = 'approved'): array
{
    return ['review_id' => strtolower((string) Str::ulid()), 'product_id' => $productId, 'decision' => $decision];
}

/** Sends $mailable through the array mailer and returns the symfony Email that was captured. */
function outboxCapture(Mailable $mailable, string $to = 'member@example.com'): Email
{
    Mail::mailer()->to($to)->send($mailable);

    return outboxSent()->last();
}

/** @return Collection<int, Email> every message the array transport holds */
function outboxSent(): Collection
{
    return collect(Mail::mailer()->getSymfonyTransport()->messages())->map(fn ($sent) => $sent->getOriginalMessage());
}

/** Forgets what the array transport captured. */
function outboxForgetSent(): void
{
    Mail::mailer()->getSymfonyTransport()->flush();
}

/**
 * Replaces the default mailer by a transport whose behaviour a test can swap at any time: `$behaviour(SentMessage)` may
 * throw (a provider failure) or return (accepted). Accepted messages are appended to `app('outbox.accepted')`.
 */
function outboxTransport(Closure $behaviour): void
{
    app()->instance('outbox.behaviour', $behaviour);
    app()->instance('outbox.accepted', new ArrayObject);
    config(['mail.default' => 'outbox_test', 'mail.mailers.outbox_test' => ['transport' => 'outbox_test']]);
    Mail::extend('outbox_test', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            (app('outbox.behaviour'))($message);
            app('outbox.accepted')->append($message);
        }

        public function __toString(): string
        {
            return 'outbox_test';
        }
    });
}

/** Swaps what the outbox_test transport does from now on. */
function outboxTransportDo(Closure $behaviour): void
{
    app()->instance('outbox.behaviour', $behaviour);
}

/** How many messages the outbox_test transport accepted. */
function outboxAccepted(): int
{
    return count(app('outbox.accepted'));
}

/** @return array<string, mixed> the stored row as a plain array */
function outboxRow(string $id): array
{
    return (array) DB::table('email_messages')->where('id', $id)->first();
}

/** Makes a row `sending`, claimed once, as ClaimDueEmails would leave it. */
function outboxClaimed(EmailMessage $message, int $attempts = 1): EmailMessage
{
    DB::table('email_messages')->where('id', $message->id)->update([
        'status' => 'sending', 'attempts' => $attempts, 'next_attempt_at' => now()->addMinutes(10),
    ]);

    return $message->refresh();
}

/** A queued, due, sendable product_live row for a verified member (a live product makes it renderable). */
function outboxReady(?User $user = null, array $attributes = []): EmailMessage
{
    $product = Product::factory()->live()->create();

    return outboxQueued(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'], $user, $attributes);
}

/** A row that was claimed once (`sending`, attempts 1) and is ready for SendEmailMessage. */
function outboxReadyClaimed(?User $user = null, int $attempts = 1): EmailMessage
{
    return outboxClaimed(outboxReady($user), $attempts);
}

/**
 * An HttpTransportException with a structured status code. The HTTP client contracts package is not installed (no HTTP
 * mailer is), so its response interface is declared here when missing.
 */
function outboxHttpError(int $status, string $message = 'provider error'): Throwable
{
    if (! interface_exists('Symfony\Contracts\HttpClient\ResponseInterface')) {
        eval('namespace Symfony\Contracts\HttpClient; interface ResponseInterface { public function getStatusCode(): int; public function getHeaders(bool $throw = true): array; public function getContent(bool $throw = true): string; public function toArray(bool $throw = true): array; public function cancel(): void; public function getInfo(?string $type = null): mixed; }');
    }

    $response = new class($status) implements ResponseInterface
    {
        public function __construct(private int $status) {}

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getHeaders(bool $throw = true): array
        {
            return [];
        }

        public function getContent(bool $throw = true): string
        {
            return '';
        }

        public function toArray(bool $throw = true): array
        {
            return [];
        }

        public function cancel(): void {}

        public function getInfo(?string $type = null): mixed
        {
            return null;
        }
    };

    return new HttpTransportException($message, $response);
}

/** A bell + mail draft for $user. */
function outboxDraft(User $user, string $dedupe = 'review:r1', ?string $emailKey = 'unset', array $payload = []): NotificationDraft
{
    $type = NotificationType::ReviewApproved;

    return new NotificationDraft(
        $user->id, $type, $dedupe,
        ['message_key' => $type->messageKey(), 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'review_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'params' => ['product_name' => 'Acme'], 'href' => '/me/products/01ARZ3NDEKTSV4RRFFQ69G5FAV'],
        $emailKey === 'unset' ? 'review_outcome:r1:'.$user->id : $emailKey,
        $payload ?: ['review_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'decision' => 'approved'],
    );
}

/** The review endpoint, with the submission token the reviewer saw. */
function producerReview(Product $product, string $decision, ?string $note = null): TestResponse
{
    $token = $product->fresh()->submitted_at->toIso8601ZuluString('microsecond');

    return test()->postJson("/api/v1/staff/products/{$product->id}/review", array_filter(['decision' => $decision, 'note' => $note, 'submitted_at' => $token], fn ($v) => $v !== null));
}

/** The bell rows and the outbox rows as plain arrays. @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
function producerRows(): array
{
    return [
        DB::table('notifications')->orderBy('created_at')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('email_messages')->orderBy('created_at')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

/** Counts of both tables, for "nothing was written" checks. @return array{notifications: int, email_messages: int} */
function producerCounts(): array
{
    return [
        'notifications' => DB::table('notifications')->count(),
        'email_messages' => DB::table('email_messages')->count(),
    ];
}
