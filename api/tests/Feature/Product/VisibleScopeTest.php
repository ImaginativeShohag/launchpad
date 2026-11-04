<?php

use App\Enums\ProductStatus;
use App\Models\Product;

it('shows only live products whose launch day has begun in UTC', function () {
    // Given it is 23:59:59 UTC on 20 October 2026 and live products launching yesterday, today and tomorrow
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:59:59 UTC'));
    $yesterday = Product::factory()->live('2026-10-19')->create();
    $today = Product::factory()->live('2026-10-20')->create();
    $tomorrow = Product::factory()->live('2026-10-21')->create();

    // When the visible scope runs
    $visible = Product::visible()->pluck('id')->all();

    // Then tomorrow is hidden
    expect($visible)->toContain($yesterday->id, $today->id)->not->toContain($tomorrow->id);

    // And one second later (00:00:00 UTC on the 21st) tomorrow is visible
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:00 UTC'));
    expect(Product::visible()->pluck('id')->all())->toContain($tomorrow->id);
});

it('judges the launch day in UTC, not in the server time zone', function () {
    // Given a time that is already the 21st in Dhaka (UTC+6) but still the 20th in UTC
    // (config alone does not move PHP's default zone mid-process, which Carbon::now() reads, so set both)
    $previousZone = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Dhaka']);
    date_default_timezone_set('Asia/Dhaka');

    try {
        $this->travelTo(new DateTimeImmutable('2026-10-20 20:00:00 UTC'));
        $tomorrow = Product::factory()->live('2026-10-21')->create();

        // When the scope runs
        // Then the product is still hidden
        expect(Product::visible()->pluck('id')->all())->not->toContain($tomorrow->id);
    } finally {
        date_default_timezone_set($previousZone);
    }
});

it('never shows a product that is not live, or that was soft-deleted', function (ProductStatus $status) {
    // Given a product in a non-live status with a past date where the schema allows one
    $dated = in_array($status, [ProductStatus::Scheduled, ProductStatus::TakenDown], true);
    Product::factory()->create(['status' => $status, 'launch_date' => $dated ? '2020-01-01' : null, 'submitted_at' => $status === ProductStatus::Pending ? now() : null]);
    Product::factory()->live()->create(['deleted_at' => now()]);

    // When the scope runs
    // Then nothing is visible
    expect(Product::visible()->count())->toBe(0);
})->with(array_values(array_filter(ProductStatus::cases(), fn (ProductStatus $s) => $s !== ProductStatus::Live)));
