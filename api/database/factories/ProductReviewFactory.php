<?php

namespace Database\Factories;

use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductReview> */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'reviewer_id' => User::factory()->role(Role::Reviewer),
            'decision' => ReviewDecision::Approved,
            'note' => null,
            'created_at' => now(),
        ];
    }
}
