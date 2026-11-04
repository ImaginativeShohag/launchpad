<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An unread `review_approved` notification with a complete, valid `data` shape. Every nullable column is set (strict
 * models); the dedupe key is unique per row so factory rows never collide.
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $productId = (string) Str::ulid();
        $reviewId = (string) Str::ulid();

        return [
            'user_id' => User::factory(),
            'type' => NotificationType::ReviewApproved,
            'data' => [
                'message_key' => 'notifications.review_approved',
                'product_id' => $productId,
                'review_id' => $reviewId,
                'params' => ['product_name' => 'Acme'],
                'href' => '/me/products/'.$productId,
            ],
            'dedupe_key' => 'test:'.Str::ulid(),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function read(?CarbonInterface $at = null): static
    {
        // A closure, so the instant follows `createdAt()` whatever the order of the calls (a CHECK needs read_at >= created_at).
        return $this->state(fn () => ['read_at' => $at ?? fn (array $attributes) => $attributes['created_at']]);
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'data' => [...$attributes['data'], 'message_key' => $type->messageKey()],
        ]);
    }

    public function createdAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['created_at' => $at, 'updated_at' => $at]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->getKey()]);
    }
}
