<?php

namespace Database\Factories;

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open `shared_device` signal about a member and a live product. Every nullable column is set (strict models) and every
 * state satisfies the CHECKs (a subject is present; the three review columns come together).
 *
 * @extends Factory<RiskSignal>
 */
class RiskSignalFactory extends Factory
{
    protected $model = RiskSignal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => RiskSignalKind::SharedDevice,
            'user_id' => User::factory(),
            'product_id' => Product::factory()->live(),
            'weight' => 40,
            'details' => ['rule' => 'shared_device', 'threshold' => 3, 'observed' => 3],
            'dedupe_key' => 'factory:'.Str::lower((string) Str::ulid()),
            'detected_at' => now()->startOfSecond(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'outcome' => null,
            'review_note' => null,
        ];
    }

    public function kind(RiskSignalKind $kind, int $weight): static
    {
        return $this->state(fn () => [
            'kind' => $kind,
            'weight' => $weight,
            'details' => ['rule' => $kind->value, 'threshold' => 1, 'observed' => 1],
        ]);
    }

    public function forUserOnly(): static
    {
        return $this->state(fn () => ['product_id' => null]);
    }

    /** A product-level signal carries no member and no weight. */
    public function forProductOnly(): static
    {
        return $this->state(fn () => ['user_id' => null, 'weight' => 0]);
    }

    public function reviewed(RiskOutcome $outcome, ?User $by = null): static
    {
        return $this->state(fn () => [
            'reviewed_by' => $by ?? User::factory(),
            'reviewed_at' => now()->startOfSecond(),
            'outcome' => $outcome,
        ]);
    }

    public function detectedAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['detected_at' => $at]);
    }
}
