<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A counted top-level comment. The factory never touches counters (`products.comments_count`, `replies_count`):
 * tests that need consistent counters go through the actions.
 *
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->live(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'reply_to_user_id' => null,
            'body' => fake()->sentence(),
            'edited_at' => null,
            'removed_at' => null,
            'removed_by' => null,
            'removed_reason' => null,
            'replies_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    /** A reply on the same product as `$parent`, answering `$answers` (default: nobody, the audience is the parent's author). */
    public function replyTo(Comment $parent, ?User $answers = null): static
    {
        return $this->state(fn () => [
            'product_id' => $parent->product_id,
            'parent_id' => $parent->getKey(),
            'reply_to_user_id' => $answers?->getKey(),
        ]);
    }

    /** Removed by staff; the CHECK needs `removed_by` together with `removed_at`. The body stays for staff. */
    public function removed(?User $by = null, string $reason = 'spam'): static
    {
        return $this->state(fn () => [
            'removed_at' => now(),
            'removed_by' => $by?->getKey() ?? User::factory()->role(Role::Reviewer),
            'removed_reason' => $reason,
        ]);
    }

    /** Deleted by the author: the body is wiped. */
    public function deletedByAuthor(): static
    {
        return $this->state(fn () => ['deleted_at' => now(), 'body' => '']);
    }

    public function createdAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['created_at' => $at, 'updated_at' => $at]);
    }
}
