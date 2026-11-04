<?php

use App\Models\Product;

it('lets the maker edit everything while the product is a draft or has changes requested', function (string $state) {
    // Given the maker's product in an editable status
    $maker = signedInMember();
    $product = Product::factory()->{$state}()->create(['maker_id' => $maker->id]);

    // When they rename it
    $response = $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed App', 'slug' => 'renamed-app']);

    // Then it is saved
    $response->assertOk()->assertJsonPath('data.name', 'Renamed App');
    expect($product->fresh()->slug)->toBe('renamed-app');
})->with(['draft' => 'complete', 'changes requested' => 'changesRequested']);

it('locks the product against any edit in the other statuses', function (string $state) {
    // Given the maker's product in a locked status
    $maker = signedInMember();
    $product = Product::factory()->{$state}()->create(['maker_id' => $maker->id, 'name' => 'Locked Name']);

    // When they try to rename it
    $response = $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed App']);

    // Then it is refused with a stable code and nothing changes
    $response->assertForbidden()->assertJsonPath('code', 'product_locked');
    expect($product->fresh()->name)->toBe('Locked Name');
})->with(['pending' => 'pending', 'approved' => 'approved', 'scheduled' => 'scheduled', 'rejected' => 'rejected', 'taken down' => 'takenDown']);

it('locks links, topics and description too in a locked status', function (string $state, array $payload) {
    // Given the maker's product in a locked status
    $maker = signedInMember();
    $product = Product::factory()->{$state}()->create(['maker_id' => $maker->id]);

    // When they try to change something other than the name
    $response = $this->patchJson("/api/v1/products/{$product->id}", $payload);

    // Then it is refused with the stable code
    $response->assertForbidden()->assertJsonPath('code', 'product_locked');
})->with(function () {
    $cases = [];
    foreach (['pending', 'approved', 'takenDown'] as $state) {
        $cases["$state links"] = [$state, ['links' => [['kind' => 'docs', 'url' => 'https://docs.example.com']]]];
        $cases["$state topics"] = [$state, ['topic_ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']]];
        $cases["$state description"] = [$state, ['description' => 'Changed']];
    }

    return $cases;
});
