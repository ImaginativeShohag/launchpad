<?php

use App\Actions\Export\DownloadExport;
use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToReadFile;

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(dashNow());
});

/** A maker's ready export with a file whose bytes are $bytes. */
function downloadReady(array $productAttributes = [], string $bytes = 'PK-zip-bytes'): array
{
    $product = exportMakerProduct($productAttributes);
    $export = exportFor($product, 'ready', ['completed_at' => '2026-10-21 08:30:00+00', 'expires_at' => now()->addDays(3)]);
    exportStoreFile($export, $bytes);

    return [$product, $export];
}

function downloadUrl(string $productId, string $exportId): string
{
    return "/api/v1/me/products/{$productId}/exports/{$exportId}/download";
}

it('streams the stored bytes to the owner with the documented headers', function () {
    // Given a ready export of a product with a messy slug
    [$product, $export] = downloadReady();
    DB::table('products')->where('id', $product->id)->update(['slug' => 'hello-world']);

    // When the owner downloads it
    $response = $this->get(downloadUrl($product->id, $export->id), ['Accept' => 'application/json']);

    // Then the body is the file, and the headers are the documented ones, with nothing that locates the file
    $response->assertOk();
    expect($response->streamedContent())->toBe('PK-zip-bytes');
    expect($response->headers->get('Content-Type'))->toBe('application/zip');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=launchpad-hello-world-20261021.zip');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    foreach ($response->headers->all() as $name => $values) {
        foreach ($values as $value) {
            expect((string) $value)->not->toContain('exports/')->not->toContain($product->id)->not->toContain($export->id);
        }
    }
});

it('reduces a slug to a-z, 0-9 and dashes for the file name, and to `product` when nothing is left', function (string $slug, string $expected) {
    // Given a slug (the products table only stores clean ones; the function is the second line of defence)
    // When it is reduced
    // Then only lower-case letters, digits and single dashes remain
    expect(DownloadExport::safeSlug($slug))->toBe($expected);
})->with([
    'plain' => ['my-app', 'my-app'],
    'symbols and capitals' => ['Hello World!!', 'hello-world'],
    'repeats and edges' => ['--a__b--', 'a-b'],
    'all symbols' => ['!!!', 'product'],
    'empty' => ['', 'product'],
    'path tricks' => ['../../etc/passwd', 'etc-passwd'],
    'quotes and newlines' => ['a"b'."\r\n".'c', 'a-b-c'],
    'unicode' => ['café-βeta', 'caf-eta'],
]);

it('answers the same 404 for foreign, other-product and unknown exports before it reveals any state', function (Closure $makeUrl, ?Role $role) {
    // Given a caller and an export that is not theirs (a queued one too: not 409)
    $caller = $role === null ? signedInMember() : signedInStaff($role);
    $url = $makeUrl($caller);
    $missing = $this->getJson(downloadUrl(exportUnknownId(), exportUnknownId()));

    // When they try to download
    $response = $this->getJson($url);

    // Then it is the one 404
    $response->assertNotFound();
    expect($response->json())->toEqual($missing->json());
})->with([
    'a foreign ready export' => [function () {
        $e = exportFor(Product::factory()->live()->create(), 'ready');

        return downloadUrl($e->product_id, $e->id);
    }, null],
    'a foreign queued export' => [function () {
        $e = exportFor(Product::factory()->live()->create(), 'queued');

        return downloadUrl($e->product_id, $e->id);
    }, null],
    'a reviewer' => [function () {
        $e = exportFor(Product::factory()->live()->create(), 'ready');

        return downloadUrl($e->product_id, $e->id);
    }, Role::Reviewer],
    'an admin' => [function () {
        $e = exportFor(Product::factory()->live()->create(), 'ready');

        return downloadUrl($e->product_id, $e->id);
    }, Role::Admin],
    'another product of the caller' => [function ($me) {
        $a = Product::factory()->live()->create(['maker_id' => $me->id]);
        $b = Product::factory()->live()->create(['maker_id' => $me->id]);
        $e = exportFor($b, 'ready');

        return downloadUrl($a->id, $e->id);
    }, null],
    'an unknown export' => [fn ($me) => downloadUrl(Product::factory()->live()->create(['maker_id' => $me->id])->id, exportUnknownId()), null],
    'a non-ulid export' => [fn ($me) => downloadUrl(Product::factory()->live()->create(['maker_id' => $me->id])->id, 'nope'), null],
    'a nul byte export id' => [fn ($me) => downloadUrl(Product::factory()->live()->create(['maker_id' => $me->id])->id, '%00'), null],
    'a soft-deleted product' => [function ($me) {
        $p = Product::factory()->live()->create(['maker_id' => $me->id]);
        $e = exportFor($p, 'ready');
        $p->delete();

        return downloadUrl($p->id, $e->id);
    }, null],
]);

it('answers 409 export_not_ready for an export that is queued, processing or failed', function (string $state) {
    // Given the owner's export in that state
    $product = exportMakerProduct();
    $export = exportFor($product, $state);

    // When they try to download
    $response = $this->getJson(downloadUrl($product->id, $export->id));

    // Then it is 409 with just a message and a code
    $response->assertStatus(409)->assertJsonPath('code', 'export_not_ready');
    expect(array_keys($response->json()))->toEqualCanonicalizing(['message', 'code']);
})->with(['queued', 'processing', 'failed']);

it('answers 410 export_expired for an expired export, one past its expires_at, and one whose file is missing', function (string $case) {
    // Given the owner's export in that situation
    [$product, $export] = downloadReady();
    match ($case) {
        'status expired' => DB::table('exports')->where('id', $export->id)->update(['status' => 'expired']),
        'past expires_at, purge not run' => DB::table('exports')->where('id', $export->id)->update(['expires_at' => now()->subSecond()]),
        'exactly at expires_at' => DB::table('exports')->where('id', $export->id)->update(['expires_at' => now()]),
        'file missing' => Storage::disk('exports')->delete($export->fresh()->file_path),
    };

    // When they try to download
    $response = $this->getJson(downloadUrl($product->id, $export->id));

    // Then it is 410 with just a message and a code, never a 500
    $response->assertStatus(410)->assertJsonPath('code', 'export_expired');
    expect(array_keys($response->json()))->toEqualCanonicalizing(['message', 'code']);
})->with(['status expired', 'past expires_at, purge not run', 'exactly at expires_at', 'file missing']);

it('still downloads an unexpired export of a taken-down product', function () {
    // Given a taken-down product with a ready export
    $maker = signedInMember();
    $product = Product::factory()->takenDown()->create(['maker_id' => $maker->id]);
    $export = exportFor($product, 'ready');
    exportStoreFile($export, 'still-mine');

    // When the owner downloads it
    $response = $this->get(downloadUrl($product->id, $export->id), ['Accept' => 'application/json']);

    // Then they get it
    $response->assertOk();
    expect($response->streamedContent())->toBe('still-mine');
});

it('refuses a suspended and an unverified member the same way for existing and missing ids', function () {
    // Given an export and a missing id
    $export = exportFor(Product::factory()->live()->create(), 'ready');
    $missing = exportUnknownId();

    // When a suspended member tries both
    Sanctum::actingAs(User::factory()->suspended()->create());
    $a = $this->getJson(downloadUrl($export->product_id, $export->id))->assertForbidden();
    $b = $this->getJson(downloadUrl($missing, $missing))->assertForbidden();

    // Then both answers are the same, and an unverified member gets its own single answer
    expect($a->json())->toEqual($b->json())->and($a->json('code'))->toBe('account_unavailable');
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->getJson(downloadUrl($export->product_id, $export->id))->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $this->getJson(downloadUrl($missing, $missing))->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('answers a guest 401', function () {
    // Given an export
    $export = exportFor(Product::factory()->live()->create(), 'ready');

    // When a guest asks
    // Then it is 401
    $this->getJson(downloadUrl($export->product_id, $export->id))->assertUnauthorized();
});

it('throttles the 21st download in an hour', function () {
    // Given a ready export
    [$product, $export] = downloadReady();

    // When the owner downloads it 21 times, with the minute bucket respected (never more than 20 a minute... the api bucket is 60)
    foreach (range(1, 20) as $i) {
        $this->get(downloadUrl($product->id, $export->id), ['Accept' => 'application/json'])->assertOk();
    }

    // Then the 21st is throttled
    $this->get(downloadUrl($product->id, $export->id), ['Accept' => 'application/json'])->assertStatus(429)->assertHeader('Retry-After');
});

it('keeps two makers apart even when their products have alike slugs', function () {
    // Given two makers with products of the same name and an export each
    [$mine, $myExport] = downloadReady([], 'mine');
    $other = Product::factory()->live()->create(['name' => $mine->name]);
    $theirs = exportFor($other, 'ready');
    exportStoreFile($theirs, 'theirs');

    // When I try to download theirs through my product, and through theirs
    // Then both are the uniform 404
    $this->getJson(downloadUrl($mine->id, $theirs->id))->assertNotFound();
    $this->getJson(downloadUrl($other->id, $theirs->id))->assertNotFound();
});

it('answers 410, never 500, when the file vanishes between the checks and the stream or cannot be read', function (string $case) {
    // Given a ready export whose disk fails to open the file (deleted meanwhile, or unreadable)
    [$product, $export] = downloadReady();
    $disk = Mockery::mock(Filesystem::class);
    $case === 'throws'
        ? $disk->shouldReceive('readStream')->andThrow(UnableToReadFile::fromLocation('x'))
        : $disk->shouldReceive('readStream')->andReturn(null);
    Storage::shouldReceive('disk')->with('exports')->andReturn($disk);

    // When the owner downloads it
    $response = $this->getJson(downloadUrl($product->id, $export->id));

    // Then it is the 410 of an expired export
    $response->assertStatus(410)->assertJsonPath('code', 'export_expired');
})->with(['throws', 'null']);
