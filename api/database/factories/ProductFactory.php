<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        /** @var string $words */
        $words = fake()->unique()->words(2, true);
        $name = Str::title($words);

        return [
            'maker_id' => User::factory(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'name' => $name,
            'tagline' => null,
            'description' => null,
            'website_url' => null,
            'logo_path' => null,
            'status' => ProductStatus::Draft,
            'launch_date' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'launched_at' => null,
            'taken_down_at' => null,
            'taken_down_by' => null,
            'takedown_reason' => null,
            'votes_count' => 0,
            'comments_count' => 0,
            'hot_score' => 0.0,
            'deleted_at' => null,
        ];
    }

    /** A draft that passes the submit completeness check: all text fields, a logo, one screenshot and one topic. */
    public function complete(): static
    {
        return $this->state(fn () => [
            'tagline' => 'Ship faster with less',
            'description' => 'A longer description of what the product does and who it is for.',
            'website_url' => 'https://launchpad-demo.dev',
            'logo_path' => 'products/logos/'.Str::lower((string) Str::ulid()).'.webp',
        ])->afterCreating(function (Product $product): void {
            $topic = Topic::factory()->create();
            DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
            $product->screenshots()->create([
                'path' => 'products/screenshots/'.Str::lower((string) Str::ulid()).'.webp',
                'position' => 0, 'width' => 1200, 'height' => 800, 'alt_text' => null,
            ]);
        });
    }

    public function pending(): static
    {
        return $this->complete()->state(fn () => ['status' => ProductStatus::Pending, 'submitted_at' => now()->subHour()]);
    }

    public function changesRequested(): static
    {
        return $this->complete()->state(fn () => ['status' => ProductStatus::ChangesRequested, 'submitted_at' => now()->subHour()]);
    }

    public function rejected(): static
    {
        return $this->complete()->state(fn () => ['status' => ProductStatus::Rejected, 'submitted_at' => now()->subHour()]);
    }

    public function approved(): static
    {
        return $this->complete()->state(fn () => [
            'status' => ProductStatus::Approved, 'submitted_at' => now()->subHours(2), 'approved_at' => now()->subHour(),
        ]);
    }

    /** @param  string|null  $date  Y-m-d (UTC); defaults to five days from now */
    public function scheduled(?string $date = null): static
    {
        return $this->complete()->state(fn () => [
            'status' => ProductStatus::Scheduled, 'submitted_at' => now()->subHours(2), 'approved_at' => now()->subHour(),
            'launch_date' => $date ?? Carbon::now('UTC')->addDays(5)->toDateString(),
        ]);
    }

    /** @param  string|null  $date  Y-m-d (UTC) of the launch day; defaults to yesterday */
    public function live(?string $date = null): static
    {
        return $this->complete()->state(fn () => [
            'status' => ProductStatus::Live, 'submitted_at' => now()->subDays(3), 'approved_at' => now()->subDays(2),
            'launch_date' => $date ?? Carbon::now('UTC')->subDay()->toDateString(), 'launched_at' => now()->subDay(),
        ]);
    }

    public function takenDown(): static
    {
        return $this->live()->state(fn () => [
            'status' => ProductStatus::TakenDown, 'taken_down_at' => now(), 'takedown_reason' => 'Test reason',
        ]);
    }
}
