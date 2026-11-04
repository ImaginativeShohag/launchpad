<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\ProductScreenshot;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

function shotDraft(string $state = 'complete'): Product
{
    return Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id]);
}

it('adds screenshots at the next position, scaled down and re-encoded', function () {
    // Given a draft that already has one screenshot (from the factory)
    $product = shotDraft();

    // When two more are uploaded, a wide one with alt text
    $a = $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg('a.jpg', 3200, 1000), 'alt_text' => '  Home page ']);
    $b = $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg('b.jpg', 640, 480)]);

    // Then they take positions 1 and 2, the wide one fits 1600px wide, and the alt text is cleaned
    $a->assertCreated()->assertJsonPath('data.position', 1)->assertJsonPath('data.width', 1600)->assertJsonPath('data.height', 500)->assertJsonPath('data.alt_text', 'Home page');
    $b->assertCreated()->assertJsonPath('data.position', 2)->assertJsonPath('data.width', 640);
    expect(array_keys($a->json('data')))->toBe(['id', 'url', 'position', 'width', 'height', 'alt_text']);
    $path = $product->screenshots()->where('position', 1)->value('path');
    expect($path)->toStartWith('products/screenshots/')->toEndWith('.webp');
    Storage::disk('public')->assertExists($path);
});

it('refuses a screenshot over the product.max_screenshots setting and leaves no file', function () {
    // Given a limit of 2 and a draft that has one screenshot
    $product = shotDraft();
    app(Settings::class)->set(SettingKey::ProductMaxScreenshots, 2, User::factory()->role(Role::Admin)->create());
    $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->assertCreated();
    $files = Storage::disk('public')->allFiles();

    // When a third is uploaded
    $response = $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()]);

    // Then it is a 422 and the rejected upload did not stay on the disk
    $response->assertUnprocessable()->assertJsonValidationErrors('screenshot');
    expect(Storage::disk('public')->allFiles())->toBe($files);
});

it('deletes a screenshot, closes the gap in the order, and removes the file after the commit', function () {
    // Given a draft with three screenshots
    $product = shotDraft();
    $ids = collect([1, 2])->map(fn () => $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->json('data.id'));
    $first = $product->screenshots()->where('position', 0)->firstOrFail();
    $middlePath = $product->screenshots()->whereKey($ids[0])->value('path');

    // When the middle one is deleted
    $this->deleteJson("/api/v1/products/{$product->id}/screenshots/{$ids[0]}")->assertNoContent();

    // Then positions are 0 and 1 again and the file is gone
    expect($product->screenshots()->pluck('position')->all())->toBe([0, 1]);
    expect($product->screenshots()->pluck('id')->all())->toBe([$first->id, $ids[1]]);
    Storage::disk('public')->assertMissing($middlePath);
});

it('keeps the file when the database delete fails', function () {
    // Given a draft with an uploaded screenshot and a database that rejects the delete
    $product = shotDraft();
    $id = $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->json('data.id');
    $path = $product->screenshots()->whereKey($id)->value('path');
    ProductScreenshot::deleting(fn () => throw new RuntimeException('db down'));

    // When it is deleted
    $response = $this->deleteJson("/api/v1/products/{$product->id}/screenshots/{$id}");

    // Then the request fails and the file is still there
    $response->assertStatus(500);
    ProductScreenshot::flushEventListeners();
    Storage::disk('public')->assertExists($path);
    expect($product->screenshots()->whereKey($id)->exists())->toBeTrue();
});

it('reorders screenshots with a two-phase update', function () {
    // Given a draft with three screenshots
    $product = shotDraft();
    $ids = $product->screenshots()->pluck('id')->all();
    foreach ([1, 2] as $_) {
        $ids[] = $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->json('data.id');
    }

    // When the order is reversed
    $response = $this->putJson("/api/v1/products/{$product->id}/screenshots/order", ['ids' => array_reverse($ids)]);

    // Then the new order is stored and returned
    $response->assertOk();
    expect($response->json('data.*.id'))->toBe(array_reverse($ids));
    expect($product->screenshots()->pluck('id')->all())->toBe(array_reverse($ids));
});

it('refuses an order that is not exactly the product screenshots', function (string $case) {
    // Given a draft with two screenshots and a foreign one
    $product = shotDraft();
    $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->assertCreated();
    $ids = $product->screenshots()->pluck('id')->all();
    $foreign = Product::factory()->complete()->create()->screenshots()->firstOrFail()->id;
    $payload = match ($case) {
        'missing one' => [$ids[0]],
        'extra foreign' => [...$ids, $foreign],
        'duplicate' => [$ids[0], $ids[0]],
        'unknown' => [$ids[0], '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        'not ulid' => [$ids[0], 'nope'],
        'empty' => [],
    };

    // When that order is sent
    $response = $this->putJson("/api/v1/products/{$product->id}/screenshots/order", ['ids' => $payload]);

    // Then it is a 422 on ids (or one of its items) and the order is unchanged
    $response->assertUnprocessable();
    expect(collect($response->json('errors'))->keys()->contains(fn (string $key) => str_starts_with($key, 'ids')))->toBeTrue();
    expect($product->screenshots()->pluck('id')->all())->toBe($ids);
})->with(['missing one', 'extra foreign', 'duplicate', 'unknown', 'not ulid', 'empty']);

it('answers wrong-shaped upload, alt text and order input with 422', function (string $endpoint, array $payload, string $field) {
    // Given a draft
    $product = shotDraft();
    $payload = array_map(fn ($v) => $v === 'SHOT' ? uploadedJpeg() : $v, $payload);

    // When a field has the wrong shape
    $response = $endpoint === 'order'
        ? $this->putJson("/api/v1/products/{$product->id}/screenshots/order", $payload)
        : $this->postJson("/api/v1/products/{$product->id}/screenshots", $payload);

    // Then it is a 422
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'shot missing' => ['upload', [], 'screenshot'],
    'shot null' => ['upload', ['screenshot' => null], 'screenshot'],
    'shot array' => ['upload', ['screenshot' => ['a']], 'screenshot'],
    'shot object' => ['upload', ['screenshot' => ['a' => 'b']], 'screenshot'],
    'shot string' => ['upload', ['screenshot' => 'x'], 'screenshot'],
    'alt array' => ['upload', ['screenshot' => 'SHOT', 'alt_text' => ['x']], 'alt_text'],
    'alt object' => ['upload', ['screenshot' => 'SHOT', 'alt_text' => ['a' => 'b']], 'alt_text'],
    'alt too long' => ['upload', ['screenshot' => 'SHOT', 'alt_text' => str_repeat('a', 201)], 'alt_text'],
    'ids missing' => ['order', [], 'ids'],
    'ids null' => ['order', ['ids' => null], 'ids'],
    'ids string' => ['order', ['ids' => 'x'], 'ids'],
    'ids object' => ['order', ['ids' => ['a' => 'b']], 'ids'],
    'ids nested' => ['order', ['ids' => [['x']]], 'ids.0'],
    'ids integer' => ['order', ['ids' => [1]], 'ids.0'],
]);

it('rejects hostile image files', function (string $name, string $bytes) {
    // Given a draft
    $product = shotDraft();

    // When such a file is uploaded
    $response = $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => UploadedFile::fake()->createWithContent($name, $bytes)]);

    // Then it is a 422 and nothing is stored beyond the factory row
    $response->assertUnprocessable()->assertJsonValidationErrors('screenshot');
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(function () {
    return [
        'php polyglot' => ['a.jpg', "<?php system('id'); ?>"],
        'svg' => ['a.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
        'animated png' => ['a.png', smallPng(animated: true, size: 400)],
        'too small' => ['a.png', smallPng(size: 319)],
        'too many pixels' => ['a.jpg', jpegWithExif(3500, 2500)],
        'bomb' => ['b.png', bombPng(20000, 20000)],
    ];
});

it('locks media outside draft and changes_requested, and hides other members products', function () {
    // Given a pending product of the maker and a foreign draft
    $maker = signedInMember();
    $pending = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $shotId = $pending->screenshots()->value('id');
    $foreign = Product::factory()->complete()->create();

    // When the maker changes media of the pending product, and touches the foreign one
    $upload = $this->postJson("/api/v1/products/{$pending->id}/screenshots", ['screenshot' => uploadedJpeg()]);
    $delete = $this->deleteJson("/api/v1/products/{$pending->id}/screenshots/{$shotId}");
    $reorder = $this->putJson("/api/v1/products/{$pending->id}/screenshots/order", ['ids' => [$shotId]]);
    $foreignUpload = $this->postJson("/api/v1/products/{$foreign->id}/screenshots", ['screenshot' => ['x']]);
    $foreignDelete = $this->deleteJson("/api/v1/products/{$foreign->id}/screenshots/{$foreign->screenshots()->value('id')}");

    // Then the first three are product_locked, the last two the uniform 404
    foreach ([$upload, $delete, $reorder] as $response) {
        $response->assertForbidden()->assertJsonPath('code', 'product_locked');
    }
    $foreignUpload->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $foreignDelete->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('answers a screenshot id from another product with 404', function () {
    // Given two drafts of the maker, the second owning the screenshot
    $maker = signedInMember();
    $a = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $b = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When the screenshot of B is deleted through A
    $response = $this->deleteJson("/api/v1/products/{$a->id}/screenshots/{$b->screenshots()->value('id')}");

    // Then it is a 404 and B keeps it
    $response->assertNotFound();
    expect($b->screenshots()->count())->toBe(1);
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a draft
    $product = Product::factory()->create();

    // When nobody, an unconfirmed member and a suspended member call
    $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->assertUnauthorized();
    $this->putJson("/api/v1/products/{$product->id}/screenshots/order", ['ids' => []])->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson("/api/v1/products/{$product->id}/screenshots", ['screenshot' => uploadedJpeg()])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->deleteJson("/api/v1/products/{$product->id}/screenshots/01JZZZZZZZZZZZZZZZZZZZZZZZ")->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('answers a malformed screenshot id with 404, never a 500', function (string $id) {
    // Given the maker's draft
    $maker = signedInMember();
    $product = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When a screenshot id that is not a ULID (or is invalid UTF-8) is deleted
    $response = $this->deleteJson("/api/v1/products/{$product->id}/screenshots/{$id}");

    // Then it is a client error (the framework answers 400 for invalid UTF-8 in the path), never a 500
    expect($response->status())->toBeIn([400, 404]);
})->with(['text' => 'not-a-ulid', 'invalid utf-8' => '%FF', 'too long' => str_repeat('a', 300), 'encoded nul' => 'a%00b']);
