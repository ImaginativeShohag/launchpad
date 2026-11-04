<?php

use App\Enums\ProductStatus;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Database\Seeders\MonthlyDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('seeds every November day with consistent activity and preserves data on rerun', function () {
    // Given a November clock and an existing product
    $this->travelTo(now('UTC')->setDate(2026, 11, 3)->setTime(12, 30));
    $existing = Product::factory()->create(['name' => 'Keep my product']);
    Event::fake(['App\\Events\\*']);
    Mail::fake();
    Queue::fake();

    // When the monthly demo is seeded twice
    $this->seed(MonthlyDemoSeeder::class);
    $this->seed(MonthlyDemoSeeder::class);

    // Then each date has ten launches, with activity only on live products
    $products = Product::query()->where('slug', 'like', 'monthly-demo-%')->get();
    expect($products)->toHaveCount(300)
        ->and($products->groupBy(fn (Product $product) => $product->launch_date->toDateString())->map->count()->unique()->values()->all())->toBe([10])
        ->and($products->where('status', ProductStatus::Live))->toHaveCount(30)
        ->and($products->where('status', ProductStatus::Scheduled))->toHaveCount(270)
        ->and(User::query()->count())->toBe(25)
        ->and(Vote::query()->count())->toBe(591)
        ->and(Comment::query()->count())->toBe(60)
        ->and(DB::table('daily_rankings')->count())->toBe(20)
        ->and($existing->fresh()->name)->toBe('Keep my product');

    foreach ($products as $product) {
        expect($product->votes_count)->toBe($product->votes()->active()->count())
            ->and($product->comments_count)->toBe($product->comments()->count())
            ->and((int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe($product->votes_count)
            ->and($product->topics()->count())->toBe(1);
    }

    expect(Vote::query()->where('voted_at', '>', now())->exists())->toBeFalse();
    Event::assertNothingDispatched();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('refuses to seed demo data outside local and testing environments', function () {
    // Given a production environment
    app()->detectEnvironment(fn () => 'production');

    // When demo seeding is attempted, then no records are written
    expect(fn () => app(MonthlyDemoSeeder::class)->run())->toThrow(LogicException::class);
    expect(User::query()->count())->toBe(0);
});
