<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

function draftWithFiles(): Product
{
    $product = Product::factory()->complete()->create(['maker_id' => signedInMember()->id]);
    Storage::disk('public')->put($product->logo_path, 'logo');
    foreach ($product->screenshots as $shot) {
        Storage::disk('public')->put($shot->path, 'shot');
    }

    return $product->load('screenshots');
}

it('soft-deletes a draft, frees its slug, removes its rows and files', function () {
    // Given a draft with a logo, a screenshot, a topic and a link
    $product = draftWithFiles();
    $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/a/b', 'position' => 0]);
    $files = [$product->logo_path, $product->screenshots[0]->path];
    $slug = $product->slug;

    // When it is deleted
    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    // Then it is soft-deleted under a freed slug, the child rows and files are gone
    $deleted = Product::withTrashed()->findOrFail($product->id);
    expect($deleted->deleted_at)->not->toBeNull()->and($deleted->slug)->toBe('deleted-'.strtolower($product->id))->and($deleted->logo_path)->toBeNull();
    expect(DB::table('product_screenshots')->where('product_id', $product->id)->count())->toBe(0);
    expect(DB::table('product_topics')->where('product_id', $product->id)->count())->toBe(0);
    expect(DB::table('product_links')->where('product_id', $product->id)->count())->toBe(0);
    foreach ($files as $file) {
        Storage::disk('public')->assertMissing($file);
    }

    // And the old slug can be used again
    $this->postJson('/api/v1/products', ['name' => 'Reborn', 'slug' => $slug])->assertCreated();
});

it('keeps review history of a deleted draft', function () {
    // Given a draft that was reviewed and withdrawn
    $product = draftWithFiles();
    ProductReview::factory()->create(['product_id' => $product->id]);

    // When it is deleted
    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    // Then the review row is still there
    expect(ProductReview::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('only deletes drafts; every other status is a 409', function (string $state) {
    // Given the maker's product in another status
    $product = Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id]);

    // When it is deleted
    $response = $this->deleteJson("/api/v1/products/{$product->id}");

    // Then it is refused and still exists
    $response->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
})->with(['pending', 'approved', 'scheduled', 'live', 'rejected', 'changesRequested', 'takenDown']);

it('keeps the files when the database fails', function () {
    // Given a draft with files and a database that rejects the write
    $product = draftWithFiles();
    Product::saving(fn () => throw new RuntimeException('db down'));

    // When it is deleted
    $response = $this->deleteJson("/api/v1/products/{$product->id}");

    // Then nothing was removed
    $response->assertStatus(500);
    Product::flushEventListeners();
    Storage::disk('public')->assertExists($product->logo_path);
    expect(DB::table('product_screenshots')->where('product_id', $product->id)->count())->toBe(1);
});

it('still answers 204 and reports it when a file cannot be removed', function () {
    // Given a draft and a disk that fails on delete
    Exceptions::fake();
    $product = draftWithFiles();
    Storage::shouldReceive('disk')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk gone'));

    // When it is deleted
    $response = $this->deleteJson("/api/v1/products/{$product->id}");

    // Then the draft is deleted and the failure was reported
    $response->assertNoContent();
    expect(Product::withTrashed()->findOrFail($product->id)->deleted_at)->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('answers another member and a deleted draft with the uniform 404 and requires sign-in', function () {
    // Given someone else's draft
    $foreign = Product::factory()->create();
    $this->deleteJson("/api/v1/products/{$foreign->id}")->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->deleteJson("/api/v1/products/{$foreign->id}")->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $maker = signedInMember();
    $gone = Product::factory()->create(['maker_id' => $maker->id, 'deleted_at' => now()]);

    // When a member deletes the foreign draft, and the maker deletes the already-deleted one
    // Then both are the same 404 and no audit row is written
    $this->deleteJson("/api/v1/products/{$foreign->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->deleteJson("/api/v1/products/{$gone->id}")->assertNotFound();
    expect(AuditLog::query()->count())->toBe(0);
});
