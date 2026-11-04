<?php

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;

it('shows staff the full detail with the review history, oldest first', function () {
    // Given a changes_requested product with two reviews by two reviewers
    $one = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one', 'email' => 'rev1@example.com']);
    $two = User::factory()->role(Role::Admin)->create(['handle' => 'adm_two']);
    $product = Product::factory()->changesRequested()->create();
    ProductReview::factory()->create(['product_id' => $product->id, 'reviewer_id' => $one->id, 'decision' => 'changes_requested', 'note' => 'Tagline unclear', 'created_at' => now()->subHours(3)]);
    ProductReview::factory()->create(['product_id' => $product->id, 'reviewer_id' => $two->id, 'decision' => 'changes_requested', 'note' => 'Add screenshots', 'created_at' => now()->subHour()]);
    signedInStaff();

    // When a reviewer opens it
    $response = $this->getJson("/api/v1/staff/products/{$product->id}");

    // Then they see maker, topics, links, screenshots and both reviews with reviewer handles (never emails)
    $response->assertOk()->assertJsonPath('data.status', 'changes_requested')
        ->assertJsonPath('data.reviews.0.note', 'Tagline unclear')->assertJsonPath('data.reviews.0.reviewer.handle', 'rev_one')
        ->assertJsonPath('data.reviews.1.reviewer.handle', 'adm_two')->assertJsonCount(1, 'data.screenshots');
    expect($response->getContent())->not->toContain('rev1@example.com');
    expect(array_keys($response->json('data.reviews.0.reviewer')))->toBe(['id', 'handle']);
});

it('shows every hidden status to staff but treats drafts as missing', function (ProductStatus $status) {
    // Given a product in some status
    $dated = in_array($status, [ProductStatus::Scheduled, ProductStatus::Live, ProductStatus::TakenDown], true);
    $product = Product::factory()->create(['status' => $status, 'launch_date' => $dated ? '2030-01-01' : null, 'submitted_at' => $status === ProductStatus::Pending ? now() : null]);
    signedInStaff();

    // When staff open it
    $response = $this->getJson("/api/v1/staff/products/{$product->id}");

    // Then drafts are the uniform 404 and everything else is visible
    if ($status === ProductStatus::Draft) {
        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    } else {
        $response->assertOk()->assertJsonPath('data.status', $status->value);
    }
})->with(ProductStatus::cases());

it('answers an unknown or soft-deleted product with 404 for staff and 403 for everyone else', function () {
    // Given a deleted pending product
    $deleted = Product::factory()->pending()->create(['deleted_at' => now()]);

    // When staff ask for it and for an unknown id
    signedInStaff();
    $this->getJson("/api/v1/staff/products/{$deleted->id}")->assertNotFound();
    $this->getJson('/api/v1/staff/products/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

    // And a member asks for an existing and an unknown id: the same 403, so ids cannot be probed
    $live = Product::factory()->live()->create();
    signedInMember();
    $this->getJson("/api/v1/staff/products/{$live->id}")->assertForbidden();
    $this->getJson('/api/v1/staff/products/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertForbidden();
});

it('requires sign-in', function () {
    // Given a pending product
    $product = Product::factory()->pending()->create();

    // When nobody is signed in
    // Then it is 401
    $this->getJson("/api/v1/staff/products/{$product->id}")->assertUnauthorized();
});
