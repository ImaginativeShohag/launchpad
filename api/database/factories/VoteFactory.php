<?php

namespace Database\Factories;

use App\Enums\VoteStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vote> */
class VoteFactory extends Factory
{
    protected $model = Vote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'status' => VoteStatus::Counted,
            'voted_at' => now(),
            'removed_at' => null,
            'toggle_count' => 0,
            'ip_hash' => hash('sha256', fake()->unique()->ipv4()),
            'device_hash' => hash('sha256', fake()->unique()->uuid()),
            'user_agent' => 'Mozilla/5.0 (Test)',
            'voided_at' => null,
            'voided_reason' => null,
        ];
    }

    /** Taken back by the member; the status is unchanged (removal is separate from voiding). */
    public function removed(): static
    {
        return $this->state(fn () => ['removed_at' => now(), 'toggle_count' => 1]);
    }

    public function flagged(): static
    {
        return $this->state(fn () => ['status' => VoteStatus::Flagged]);
    }

    /** The CHECK needs voided_at together with the voided status. */
    public function voided(string $reason = 'test'): static
    {
        return $this->state(fn () => ['status' => VoteStatus::Voided, 'voided_at' => now(), 'voided_reason' => $reason]);
    }
}
