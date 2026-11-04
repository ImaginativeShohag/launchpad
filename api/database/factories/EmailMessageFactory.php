<?php

namespace Database\Factories;

use App\Enums\EmailStatus;
use App\Enums\EmailType;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A due, `queued` review_outcome row. Every nullable column is set (strict models) and every state satisfies the CHECKs
 * (a due time exactly for queued/sending, `sent_at` exactly for sent).
 *
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'type' => EmailType::ReviewOutcome,
            'idempotency_key' => 'test:'.Str::ulid(),
            'to_email' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('email') ?? 'someone@example.com',
            'payload' => ['review_id' => (string) Str::ulid(), 'product_id' => (string) Str::ulid(), 'decision' => 'approved'],
            'status' => EmailStatus::Queued,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_error' => null,
            'provider_message_id' => null,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function queued(?CarbonInterface $due = null): static
    {
        return $this->state(fn () => ['status' => EmailStatus::Queued, 'next_attempt_at' => $due ?? now(), 'sent_at' => null]);
    }

    public function sending(CarbonInterface $leaseEnd, int $attempts = 1): static
    {
        return $this->state(fn () => ['status' => EmailStatus::Sending, 'next_attempt_at' => $leaseEnd, 'attempts' => $attempts, 'sent_at' => null]);
    }

    public function sent(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => ['status' => EmailStatus::Sent, 'next_attempt_at' => null, 'attempts' => 1, 'sent_at' => $at ?? now(), 'provider_message_id' => 'msg-'.Str::ulid()]);
    }

    public function failed(string $error = 'RuntimeException: boom'): static
    {
        return $this->state(fn () => ['status' => EmailStatus::Failed, 'next_attempt_at' => null, 'attempts' => 9, 'sent_at' => null, 'last_error' => $error]);
    }

    public function suppressed(string $reason = 'opted_out'): static
    {
        return $this->state(fn () => ['status' => EmailStatus::Suppressed, 'next_attempt_at' => null, 'sent_at' => null, 'last_error' => $reason]);
    }

    /** @param  array<string, mixed>  $payload */
    public function ofType(EmailType $type, array $payload): static
    {
        return $this->state(fn () => ['type' => $type, 'payload' => $payload]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->getKey(), 'to_email' => $user->email]);
    }

    public function createdAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['created_at' => $at, 'updated_at' => $at]);
    }
}
