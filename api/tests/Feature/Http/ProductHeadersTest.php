<?php

use App\Models\Comment;
use App\Models\Product;

it('forbids caching of product writes and of founder, staff and rejected responses', function () {
    // Given a member and a product
    $maker = signedInMember();
    $product = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When a write, a patch, the founder detail, the founder list and a rejected write are made
    $create = $this->postJson('/api/v1/products', ['name' => 'Cache Test', 'slug' => 'cache-test']);
    $patch = $this->patchJson("/api/v1/products/{$product->id}", ['tagline' => 'A fresh tagline']);
    $detail = $this->getJson("/api/v1/me/products/{$product->id}");
    $list = $this->getJson('/api/v1/me/products');
    $invalid = $this->postJson('/api/v1/products', ['name' => ['x']]);
    $missing = $this->deleteJson('/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV');

    // Then none of them may be cached
    foreach ([$create, $patch, $detail, $list, $invalid, $missing] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect($create->status())->toBe(201)->and($invalid->status())->toBe(422)->and($missing->status())->toBe(404);
});

it('keeps public product reads cacheable by headers and signed-out writes private', function () {
    // Given a live product
    $product = Product::factory()->live()->create();

    // When the public reads and an unauthenticated write are made
    $show = $this->getJson("/api/v1/products/{$product->slug}");
    $list = $this->getJson('/api/v1/topics');
    $makerProducts = $this->getJson("/api/v1/users/{$product->maker->handle}/products");
    $unauthenticated = $this->postJson('/api/v1/products', ['name' => 'X Y', 'slug' => 'x-y-z']);

    // Then reads are not marked no-store, and even the 401 for a write is
    expect($show->status())->toBe(200);
    foreach ([$show, $list, $makerProducts] as $response) {
        expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
    }
    expect($unauthenticated->status())->toBe(401)->and($unauthenticated->headers->get('Cache-Control'))->toContain('no-store');
});

it('deliberately leaves the public viewer-aware reads out of the no-store prefixes', function () {
    // Given the public reads that know an optional viewer
    $this->travelTo(new DateTimeImmutable('2026-10-20 12:00:00 UTC'));
    $product = Product::factory()->live(now('UTC')->toDateString())->create();

    // When a guest reads them
    // Then SecurityHeaders does not mark them no-store (only a known viewer's response is, set by the read itself)
    expect($this->getJson('/api/v1/today')->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($this->getJson("/api/v1/products/{$product->slug}")->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($this->getJson('/api/v1/leaderboards?period=all')->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($this->getJson('/api/v1/days/'.now('UTC')->toDateString())->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($this->getJson('/api/v1/search?q=launch')->headers->get('Cache-Control'))->not->toContain('no-store');
    expect(file_get_contents(app_path('Http/Middleware/SecurityHeaders.php')))->not->toContain('api/v1/today')->not->toContain('api/v1/leaderboards')->not->toContain('api/v1/days')->not->toContain('api/v1/search');
});

it('forbids caching of every vote write, including the refusals', function () {
    // Given a member, a product launching today, one that launched yesterday and the member's own product
    $this->travelTo(new DateTimeImmutable('2026-10-20 12:00:00 UTC'));
    $member = signedInMember();
    $today = Product::factory()->live(now('UTC')->toDateString())->create();
    $yesterday = Product::factory()->live(now('UTC')->subDay()->toDateString())->create();
    $own = Product::factory()->live(now('UTC')->toDateString())->create(['maker_id' => $member->id]);

    // When a cast, a withdraw, a closed day, an own product and an unknown id are sent
    $cast = $this->putJson("/api/v1/products/{$today->id}/vote");
    $withdraw = $this->deleteJson("/api/v1/products/{$today->id}/vote");
    $closed = $this->putJson("/api/v1/products/{$yesterday->id}/vote");
    $ownProduct = $this->putJson("/api/v1/products/{$own->id}/vote");
    $missing = $this->putJson('/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/vote');
    $this->app['auth']->forgetGuards();
    $unauthenticated = $this->putJson("/api/v1/products/{$today->id}/vote");

    // Then every answer is no-store and has the status the rules promise
    expect([$cast->status(), $withdraw->status(), $closed->status(), $ownProduct->status(), $missing->status()])->toBe([200, 200, 409, 403, 404]);
    expect($closed->json('code'))->toBe('voting_closed')->and($ownProduct->json('code'))->toBe('own_product');
    foreach ([$cast, $withdraw, $closed, $ownProduct, $missing, $unauthenticated] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect($unauthenticated->status())->toBe(401);
});

it('forbids caching of every comment write, including the refusals', function () {
    // Given a member, a product and one of the member's comments
    $member = signedInMember();
    $product = Product::factory()->live()->create();
    $comment = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $member->id, 'body' => 'Original text']);

    // When a create, a refused create, an edit, a refused edit and a delete are sent
    $created = $this->postJson("/api/v1/products/{$product->id}/comments", ['body' => 'Hello there']);
    $invalid = $this->postJson("/api/v1/products/{$product->id}/comments", ['body' => ['x']]);
    $hidden = $this->postJson('/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/comments', ['body' => 'Hello there']);
    $edited = $this->patchJson("/api/v1/comments/{$comment->id}", ['body' => 'Edited']);
    $missingEdit = $this->patchJson('/api/v1/comments/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['body' => 'Edited']);
    $deleted = $this->deleteJson("/api/v1/comments/{$comment->id}");
    $this->app['auth']->forgetGuards();
    $guest = $this->deleteJson("/api/v1/comments/{$comment->id}");

    // Then every answer is no-store and has the status the rules promise
    expect([$created->status(), $invalid->status(), $hidden->status(), $edited->status(), $missingEdit->status(), $deleted->status(), $guest->status()])->toBe([201, 422, 404, 200, 404, 200, 401]);
    foreach ([$created, $invalid, $hidden, $edited, $missingEdit, $deleted, $guest] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('forbids caching of the view beacon response, a 204 and its 404 alike', function () {
    // Given a visible product and a hidden one
    viewRedisReset();
    $product = viewVisibleProduct();

    // When the beacon is posted
    $counted = viewBeacon($product->id, [], viewDevice('device-a'));
    $missing = viewBeacon('01ARZ3NDEKTSV4RRFFQ69G5FAV', [], viewDevice('device-a'));

    // Then both answers are no-store, private (resolution 6: a POST under products/ is never cacheable)
    $counted->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
    $missing->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
});
