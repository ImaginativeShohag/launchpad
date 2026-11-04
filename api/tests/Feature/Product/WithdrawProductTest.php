<?php

use App\Enums\ProductStatus;
use App\Events\ProductWithdrawn;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

it('withdraws a pending, approved or scheduled product back to a draft and clears the launch day', function (string $state) {
    // Given the maker's product in a withdrawable status
    Event::fake([ProductWithdrawn::class]);
    $product = Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id]);

    // When they withdraw it
    $response = $this->postJson("/api/v1/products/{$product->id}/withdraw");

    // Then it is an editable draft again, with no launch day, approval or submission stamp
    $response->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.launch_date', null);
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::Draft)->and($fresh->launch_date)->toBeNull()
        ->and($fresh->approved_at)->toBeNull()->and($fresh->submitted_at)->toBeNull();
    expect($response->json('data.editable_fields'))->toContain('name', 'slug');
    Event::assertDispatched(ProductWithdrawn::class, fn (ProductWithdrawn $e) => $e->productId === $product->id);
})->with(['pending', 'approved', 'scheduled']);

it('refuses to withdraw anything else with 409', function (string $state) {
    // Given the maker's product in another status
    $product = Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id]);

    // When they withdraw it
    $response = $this->postJson("/api/v1/products/{$product->id}/withdraw");

    // Then it is refused and unchanged
    $response->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    expect($product->fresh()->status)->toBe($product->status);
})->with(['complete', 'changesRequested', 'live', 'rejected', 'takenDown']);

it('lets a withdrawn product be submitted again', function () {
    // Given a withdrawn product
    $product = Product::factory()->pending()->create(['maker_id' => signedInMember()->id]);
    $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertOk();

    // When it is submitted
    // Then it is pending again
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
});

it('ignores junk bodies, hides other members products and requires sign-in', function () {
    // Given someone else's pending product and the maker's own
    $foreign = Product::factory()->pending()->create();
    $this->postJson("/api/v1/products/{$foreign->id}/withdraw")->assertUnauthorized();
    $own = Product::factory()->pending()->create(['maker_id' => signedInMember()->id]);

    // When junk is sent to the own product, and the foreign one is withdrawn
    $junk = $this->postJson("/api/v1/products/{$own->id}/withdraw", ['x' => ['a' => 'b']]);
    $foreignResponse = $this->postJson("/api/v1/products/{$foreign->id}/withdraw");

    // Then the junk is ignored and the foreign one is the uniform 404
    $junk->assertOk();
    $foreignResponse->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($foreign->fresh()->status)->toBe(ProductStatus::Pending);
});

it('blocks unconfirmed and suspended members', function () {
    // Given a pending product
    $product = Product::factory()->pending()->create();

    // When an unconfirmed and a suspended member withdraw
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('audits the withdrawal with the status it left and fires the event after commit with that status', function () {
    // Given a scheduled product and a real listener (no fake)
    $seen = [];
    Event::listen(ProductWithdrawn::class, function (ProductWithdrawn $e) use (&$seen) {
        $seen[] = $e->fromStatus;
    });
    $product = Product::factory()->scheduled()->create(['maker_id' => signedInMember()->id]);

    // When the maker withdraws it
    $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertOk();

    // Then one audit row records where it came from, and the listener saw the same
    $audit = AuditLog::query()->where('action', 'product.withdrawn')->sole();
    expect($audit->meta)->toEqual(['from_status' => 'scheduled'])->and($audit->subject_id)->toBe($product->id);
    expect($seen)->toBe(['scheduled']);
});

it('writes no audit row and fires no event when the withdrawal is refused', function () {
    // Given a live product (cannot be withdrawn) and a real listener
    $fired = 0;
    Event::listen(ProductWithdrawn::class, function () use (&$fired) {
        $fired++;
    });
    $product = Product::factory()->live()->create(['maker_id' => signedInMember()->id]);

    // When the maker tries
    $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertStatus(409);

    // Then nothing was recorded or dispatched
    expect(AuditLog::query()->where('action', 'product.withdrawn')->count())->toBe(0)->and($fired)->toBe(0);
});
