<?php

use App\Enums\ProductStatus;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum'])->get('api/v1/_probe/products/{product}', function (Product $product) {
        Gate::authorize('manage', $product);

        return response()->json(['ok' => true]);
    });
});

it('answers a foreign product and a missing product with the identical 404 body', function () {
    // Given someone else's product and a signed-in member
    $foreign = Product::factory()->create();
    signedInMember();

    // When they ask for it, and for an id that does not exist
    $foreignResponse = $this->getJson("/api/v1/_probe/products/{$foreign->id}");
    $missingResponse = $this->getJson('/api/v1/_probe/products/01JZZZZZZZZZZZZZZZZZZZZZZZ');

    // Then both are the same short JSON 404
    $foreignResponse->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $missingResponse->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($foreignResponse->headers->get('content-type'))->toBe($missingResponse->headers->get('content-type'));
});

it('lets the maker through', function () {
    // Given a product and its maker
    $maker = signedInMember();
    $product = Product::factory()->create(['maker_id' => $maker->id]);

    // When the maker asks
    // Then the policy allows it
    $this->getJson("/api/v1/_probe/products/{$product->id}")->assertOk();
});

it('renders a rule violation as JSON with its code and status', function () {
    // Given a status the product cannot leave
    $product = Product::factory()->create(['status' => ProductStatus::Rejected]);

    // When a transition is attempted on the model
    $attempt = fn () => $product->transitionTo(ProductStatus::Pending);

    // Then the domain exception carries the code the clients switch on
    expect($attempt)->toThrow(ProductRuleViolation::class);
    try {
        $attempt();
    } catch (ProductRuleViolation $e) {
        expect($e->errorCode)->toBe('invalid_status')->and($e->status)->toBe(409);
        expect($e->render()->getStatusCode())->toBe(409);
        expect($e->render()->getData(true))->toBe(['message' => 'This product cannot do that in its current status.', 'code' => 'invalid_status']);
    }
});

it('moves the in-memory status along an allowed edge without saving it', function () {
    // Given a draft
    $product = Product::factory()->create();

    // When it transitions to pending
    $product->transitionTo(ProductStatus::Pending);

    // Then the attribute changed but nothing was written
    expect($product->status)->toBe(ProductStatus::Pending);
    expect(Product::query()->findOrFail($product->id)->status)->toBe(ProductStatus::Draft);
});
