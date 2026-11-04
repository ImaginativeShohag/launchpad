<?php

use App\Models\Product;
use App\Rules\ProductSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

// This file writes products; without a rolled-back transaction the rows would stay and break the next run.
uses(RefreshDatabase::class);

function slugPasses(mixed $value, ?string $ignore = null): bool
{
    return Validator::make(['slug' => $value], ['slug' => [new ProductSlug($ignore)]])->passes();
}

it('accepts a well-formed free slug', function () {
    // Given / When / Then
    expect(slugPasses('my-product-2'))->toBeTrue();
});

it('refuses malformed, reserved and non-string slugs', function (mixed $value) {
    // Given / When / Then
    expect(slugPasses($value))->toBeFalse();
})->with([
    'too short' => 'ab', 'too long' => str_repeat('a', 81), 'uppercase' => 'My-App', 'space' => 'my app',
    'double hyphen' => 'my--app', 'leading hyphen' => '-app', 'trailing hyphen' => 'app-', 'underscore' => 'my_app',
    'non ascii' => 'café-app', 'reserved' => 'admin', 'reserved topics' => 'topics', 'deleted prefix' => 'deleted-abc',
    'array' => [['my-app']], 'object' => [['a' => 'b']], 'integer' => 12345, 'null' => [null], 'bool' => [true],
]);

it('refuses a slug that is taken, trashed ones included, unless it is the product itself', function () {
    // Given a live product and a soft-deleted one
    $taken = Product::factory()->create(['slug' => 'taken-slug']);
    Product::factory()->create(['slug' => 'trashed-slug', 'deleted_at' => now()]);

    // When checked
    // Then both are taken, but the owner may keep its own
    expect(slugPasses('taken-slug'))->toBeFalse();
    expect(slugPasses('trashed-slug'))->toBeFalse();
    expect(slugPasses('taken-slug', $taken->id))->toBeTrue();
});
