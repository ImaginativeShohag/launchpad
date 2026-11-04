<?php

namespace Database\Factories;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A `queued` export of a live product and its maker. Every nullable column is set (strict models) and every state
 * satisfies the CHECKs (a `ready` row carries its file columns, `error` only on `failed`).
 *
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $product = Product::factory()->live();

        return [
            'product_id' => $product,
            'user_id' => fn (array $attributes) => Product::query()->whereKey($attributes['product_id'])->value('maker_id'),
            'status' => ExportStatus::Queued,
            'file_path' => null,
            'file_size' => null,
            'row_counts' => null,
            'error' => null,
            'completed_at' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => ExportStatus::Processing]);
    }

    public function ready(?string $path = null, int $size = 1234): static
    {
        $key = strtolower((string) Str::ulid());

        return $this->state(fn () => [
            'status' => ExportStatus::Ready,
            'file_path' => fn (array $attributes) => $path ?? $attributes['product_id'].'/'.$key.'.zip',
            'file_size' => $size,
            'row_counts' => ['summary' => 13, 'hourly' => 24, 'referrers_daily' => 2, 'rank_timeline' => 3],
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
            'error' => null,
        ]);
    }

    public function failed(string $code = 'generation_failed'): static
    {
        return $this->state(fn () => ['status' => ExportStatus::Failed, 'error' => $code, 'file_path' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => ExportStatus::Expired, 'error' => null, 'file_path' => null]);
    }

    public function createdAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['created_at' => $at, 'updated_at' => $at]);
    }

    public function expiresAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['expires_at' => $at]);
    }

    public function updatedAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['updated_at' => $at]);
    }
}
