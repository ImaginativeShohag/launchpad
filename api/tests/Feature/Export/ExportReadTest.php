<?php

use App\Enums\Role;
use App\Models\Export;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(function () {
    $this->travelTo(dashNow());
    Storage::fake('exports');
});

it('lists at most five exports newest first, breaking a created_at tie by id', function () {
    // Given a maker with seven exports of a product, two sharing one instant
    $product = exportMakerProduct();
    $ids = [];
    foreach (range(1, 7) as $i) {
        $at = $i >= 6 ? now()->subHours(1) : now()->subHours(10 - $i);
        $ids[] = Export::factory()->failed()->createdAt($at)->create(['product_id' => $product->id, 'user_id' => $product->maker_id])->id;
    }
    $tie = collect([$ids[5], $ids[6]])->sortDesc()->values()->all();

    // When they list
    $response = $this->getJson("/api/v1/me/products/{$product->id}/exports")->assertOk();

    // Then five come back, newest first, and the tie is broken by id descending
    $listed = array_column($response->json('data'), 'id');
    expect($listed)->toHaveCount(5)->and(array_slice($listed, 0, 2))->toBe($tie)->and($listed[2])->toBe($ids[4]);
});

it('shows one export with exactly the documented keys and never a path or url', function () {
    // Given a ready export with a file
    $product = exportMakerProduct();
    $export = exportFor($product, 'ready');
    exportStoreFile($export);

    // When the maker reads it
    $response = $this->getJson("/api/v1/me/products/{$product->id}/exports/{$export->id}")->assertOk();

    // Then only the documented keys are there, with the size and counts, and nothing that locates the file
    expect(array_keys($response->json('data')))->toBe(['id', 'product_id', 'status', 'created_at', 'completed_at', 'expires_at', 'file_size', 'row_counts', 'error']);
    $response->assertJsonPath('data.status', 'ready')->assertJsonPath('data.file_size', 1234)->assertJsonPath('data.row_counts.hourly', 24);
    $body = $response->getContent();
    expect($body)->not->toContain('.zip')->not->toContain('exports/')->not->toContain('file_path')->not->toContain('download_url');
    expect($response->headers->get('Retry-After'))->toBeNull();
});

it('sends Retry-After 5 while an export is queued or processing and not afterwards', function (string $state, bool $retry) {
    // Given an export in a state
    $product = exportMakerProduct();
    $export = exportFor($product, $state);

    // When it is polled
    $response = $this->getJson("/api/v1/me/products/{$product->id}/exports/{$export->id}")->assertOk();

    // Then the header is there only while it is in flight
    expect($response->headers->get('Retry-After'))->toBe($retry ? '5' : null);
})->with([['queued', true], ['processing', true], ['ready', false], ['failed', false], ['expired', false]]);

it('shows a failed export with its short code only', function () {
    // Given a failed export
    $product = exportMakerProduct();
    $export = exportFor($product, 'failed');

    // When it is read
    // Then the code is the stored short one
    $this->getJson("/api/v1/me/products/{$product->id}/exports/{$export->id}")->assertOk()->assertJsonPath('data.error', 'generation_failed')->assertJsonPath('data.status', 'failed');
});

it('answers the same 404 for any export that is not the caller\'s own on that product', function (Closure $makeUrl, ?Role $role) {
    // Given a caller and a product/export pair that is not theirs
    $caller = $role === null ? signedInMember() : signedInStaff($role);
    $url = $makeUrl($caller);
    $missing = $this->getJson('/api/v1/me/products/'.exportUnknownId().'/exports/'.exportUnknownId());

    // When they read it
    $response = $this->getJson($url);

    // Then it is the one 404 of a missing export
    $response->assertNotFound();
    expect($response->json())->toEqual($missing->json());
})->with([
    'a foreign member\'s export' => [function () {
        $theirs = exportFor(Product::factory()->live()->create(), 'ready');

        return "/api/v1/me/products/{$theirs->product_id}/exports/{$theirs->id}";
    }, null],
    'a reviewer on a member export' => [function () {
        $theirs = exportFor(Product::factory()->live()->create(), 'ready');

        return "/api/v1/me/products/{$theirs->product_id}/exports/{$theirs->id}";
    }, Role::Reviewer],
    'an admin on a member export' => [function () {
        $theirs = exportFor(Product::factory()->live()->create(), 'ready');

        return "/api/v1/me/products/{$theirs->product_id}/exports/{$theirs->id}";
    }, Role::Admin],
    'the caller\'s export read through another of their products' => [function ($me) {
        $a = Product::factory()->live()->create(['maker_id' => $me->id]);
        $b = Product::factory()->live()->create(['maker_id' => $me->id]);
        $export = exportFor($b, 'ready');

        return "/api/v1/me/products/{$a->id}/exports/{$export->id}";
    }, null],
    'an unknown export id' => [function ($me) {
        $a = Product::factory()->live()->create(['maker_id' => $me->id]);

        return "/api/v1/me/products/{$a->id}/exports/".exportUnknownId();
    }, null],
    'a non-ulid export id' => [function ($me) {
        $a = Product::factory()->live()->create(['maker_id' => $me->id]);

        return "/api/v1/me/products/{$a->id}/exports/not-a-ulid";
    }, null],
    'a soft-deleted product' => [function ($me) {
        $a = Product::factory()->live()->create(['maker_id' => $me->id]);
        $export = exportFor($a, 'ready');
        $a->delete();

        return "/api/v1/me/products/{$a->id}/exports/{$export->id}";
    }, null],
]);

it('lists nothing of another member\'s product: the same 404', function () {
    // Given a product of someone else with exports
    signedInMember();
    $theirs = Product::factory()->live()->create();
    exportFor($theirs, 'ready');
    $missing = $this->getJson('/api/v1/me/products/'.exportUnknownId().'/exports');

    // When the list is requested
    $response = $this->getJson("/api/v1/me/products/{$theirs->id}/exports");

    // Then it is the 404 of a missing product
    $response->assertNotFound();
    expect($response->json())->toEqual($missing->json());
});

it('answers an empty list for a maker without exports and ignores query and body input', function () {
    // Given a maker with no export
    $product = exportMakerProduct();

    // When they list with hostile query parameters
    $response = $this->getJson("/api/v1/me/products/{$product->id}/exports?limit[]=1&page=-4&status=ready");

    // Then the list is empty and fine
    $response->assertOk()->assertJsonPath('data', []);
});

it('serves the list from exports_product_created_index', function () {
    // Given an analysed table with rows
    $product = exportMakerProduct();
    foreach (range(1, 8) as $i) {
        Export::factory()->failed()->createdAt(now()->subMinutes($i))->create(['product_id' => $product->id, 'user_id' => $product->maker_id]);
    }
    DB::statement('ANALYZE exports');
    $sql = null;
    DB::listen(function ($query) use (&$sql) {
        if (str_contains($query->sql, 'from "exports"') && str_contains($query->sql, 'order by')) {
            $sql = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
        }
    });
    $this->getJson("/api/v1/me/products/{$product->id}/exports")->assertOk();

    // When its plan is read with the planner pushed toward indexes
    expect($sql)->not->toBeNull();
    $plan = retentionPlan($sql);

    // Then it uses the index made for it
    expect($plan)->toContain('exports_product_created_index');
});
