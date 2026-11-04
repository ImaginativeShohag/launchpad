<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

function logoDraft(string $state = 'complete'): Product
{
    return Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id, 'logo_path' => null]);
}

it('stores the logo as a 512px WebP and replaces the old file', function () {
    // Given a draft
    $product = logoDraft();

    // When a logo is uploaded, then another
    $first = $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => uploadedJpeg('a.jpg', 800, 600)]);
    $firstPath = $product->fresh()->logo_path;
    $second = $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => uploadedJpeg('b.jpg', 600, 600)]);
    $secondPath = $product->fresh()->logo_path;

    // Then the logo is a 512x512 WebP without metadata, and the old file is gone only after the second succeeded
    $first->assertOk()->assertJsonPath('data.logo_url', Storage::disk('public')->url($firstPath));
    $info = getimagesizefromstring(Storage::disk('public')->get($secondPath));
    expect($info[0])->toBe(512)->and($info[1])->toBe(512)->and($info[2])->toBe(IMAGETYPE_WEBP);
    expect($secondPath)->toStartWith('products/logos/')->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath);
    $second->assertOk();
});

it('removes the logo and its file', function () {
    // Given a draft with a logo
    $product = logoDraft();
    $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => uploadedJpeg()])->assertOk();
    $path = $product->fresh()->logo_path;

    // When it is deleted (twice: the second is harmless)
    $this->deleteJson("/api/v1/products/{$product->id}/logo")->assertNoContent();
    $this->deleteJson("/api/v1/products/{$product->id}/logo")->assertNoContent();

    // Then the file and the column are gone
    Storage::disk('public')->assertMissing($path);
    expect($product->fresh()->logo_path)->toBeNull();
});

it('keeps the old logo and leaves no new file when the database update fails', function () {
    // Given a draft with a logo and a database that rejects the update
    $product = logoDraft();
    $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => uploadedJpeg()])->assertOk();
    $old = $product->fresh()->logo_path;
    Product::saving(fn () => throw new RuntimeException('db down'));

    // When another logo is uploaded
    $response = $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => uploadedJpeg('b.jpg')]);

    // Then the request fails, the old file stays and no orphan was left
    $response->assertStatus(500);
    Product::flushEventListeners();
    expect(Storage::disk('public')->allFiles())->toBe([$old]);
    expect($product->fresh()->logo_path)->toBe($old);
});

it('rejects files that are not small, still, real images', function (string $name, string $bytes) {
    // Given a draft
    $product = logoDraft();

    // When such a file is uploaded
    $response = $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => UploadedFile::fake()->createWithContent($name, $bytes)]);

    // Then it is a 422 that leaks nothing, and nothing is stored
    $response->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect($response->getContent())->not->toContain('Intervention')->not->toContain('/var/www');
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect($product->fresh()->logo_path)->toBeNull();
})->with(function () {
    return [
        'svg' => ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>'],
        'php polyglot' => ['a.jpg', "<?php system('id'); ?>"],
        'empty file' => ['a.jpg', ''],
        'gif' => ['a.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff;"],
        'animated png' => ['a.png', smallPng(animated: true, size: 200)],
        'too small' => ['a.png', smallPng(size: 127)],
        'too wide' => ['a.jpg', jpegWithExif(4001, 200)],
        'too many pixels' => ['a.jpg', jpegWithExif(3500, 2500)],
        'decompression bomb' => ['bomb.png', bombPng(20000, 20000)],
    ];
});

it('rejects a file over 2 MB', function () {
    // Given a draft
    $product = logoDraft();

    // When a 3 MB file is uploaded
    $response = $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => UploadedFile::fake()->image('big.jpg', 600, 600)->size(3000)]);

    // Then it is refused
    $response->assertUnprocessable()->assertJsonValidationErrors('logo');
});

it('rejects array, object, null and missing logo fields', function (array $payload) {
    // Given a draft
    $product = logoDraft();

    // When the field is not a single file
    $response = $this->postJson("/api/v1/products/{$product->id}/logo", $payload);

    // Then it is a 422
    $response->assertUnprocessable()->assertJsonValidationErrors('logo');
})->with([
    'missing' => [[]], 'null' => [['logo' => null]], 'string' => [['logo' => 'nope']], 'array' => [['logo' => ['a']]],
    'object' => [['logo' => ['a' => 'b']]], 'integer' => [['logo' => 5]], 'true' => [['logo' => true]],
]);

it('allows media only while the product is a draft or has changes requested, and does not even decode otherwise', function (string $state, bool $allowed) {
    // Given the maker's product in some status
    $product = logoDraft($state);

    // When a logo is uploaded
    $response = $this->postJson("/api/v1/products/{$product->id}/logo", ['logo' => uploadedJpeg()]);

    // Then it is stored or refused with a stable code, and a refusal leaves no file behind
    if ($allowed) {
        $response->assertOk();
    } else {
        $response->assertForbidden()->assertJsonPath('code', 'product_locked');
        expect(Storage::disk('public')->allFiles())->toBe([]);
    }
})->with([
    'draft' => ['complete', true], 'changes requested' => ['changesRequested', true], 'pending' => ['pending', false],
    'approved' => ['approved', false], 'scheduled' => ['scheduled', false], 'live' => ['live', false],
    'rejected' => ['rejected', false], 'taken down' => ['takenDown', false],
]);

it('answers another member with the same 404 as for a missing product, before validating', function () {
    // Given someone else's draft and a hostile upload
    $foreign = Product::factory()->create();
    signedInMember();

    // When a non-owner uploads garbage, deletes, and the product id is unknown
    $upload = $this->postJson("/api/v1/products/{$foreign->id}/logo", ['logo' => ['x']]);
    $delete = $this->deleteJson("/api/v1/products/{$foreign->id}/logo");
    $missing = $this->postJson('/api/v1/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/logo', ['logo' => uploadedJpeg()]);

    // Then every answer is the identical 404
    foreach ([$upload, $delete, $missing] as $response) {
        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a draft
    $product = Product::factory()->create();
    $upload = ['logo' => uploadedJpeg()];

    // When nobody, an unconfirmed member and a suspended member upload
    $this->postJson("/api/v1/products/{$product->id}/logo", $upload)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson("/api/v1/products/{$product->id}/logo", $upload)->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->postJson("/api/v1/products/{$product->id}/logo", $upload)->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});
