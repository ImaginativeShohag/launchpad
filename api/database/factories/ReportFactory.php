<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open report of a comment. Every nullable column is set (strict models), and the closed states set `handled_at`
 * because a CHECK ties it to the status.
 *
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'reportable_type' => 'comment',
            'reportable_id' => fn () => Comment::factory()->create()->getKey(),
            'reason' => ReportReason::Spam,
            'details' => null,
            'status' => ReportStatus::Open,
            'handled_by' => null,
            'handled_at' => null,
            'resolution_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn () => ['reportable_type' => 'product', 'reportable_id' => $product->getKey()]);
    }

    public function forComment(Comment $comment): static
    {
        return $this->state(fn () => ['reportable_type' => 'comment', 'reportable_id' => $comment->getKey()]);
    }

    public function resolved(?User $by = null): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Resolved,
            'handled_by' => $by?->getKey() ?? User::factory(),
            'handled_at' => now(),
        ]);
    }

    public function dismissed(User $by): static
    {
        return $this->state(fn () => ['status' => ReportStatus::Dismissed, 'handled_by' => $by->getKey(), 'handled_at' => now()]);
    }

    /** Closed by the system: the target was deleted by its author, so no staff member handled it. */
    public function systemResolved(): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Resolved,
            'handled_by' => null,
            'handled_at' => now(),
            'resolution_note' => 'deleted_by_author',
        ]);
    }
}
