<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('lists only the visible products of that member, newest launch first', function () {
    // Given a member with two live products and several hidden ones, and another member's live product
    $maker = User::factory()->create(['handle' => 'amina_r']);
    $older = Product::factory()->live()->create(['maker_id' => $maker->id, 'launched_at' => now()->subDays(3)]);
    $newer = Product::factory()->live()->create(['maker_id' => $maker->id, 'launched_at' => now()->subDay()]);
    Product::factory()->pending()->create(['maker_id' => $maker->id]);
    Product::factory()->scheduled()->create(['maker_id' => $maker->id]);
    Product::factory()->takenDown()->create(['maker_id' => $maker->id]);
    Product::factory()->live()->create(['maker_id' => $maker->id, 'deleted_at' => now()]);
    Product::factory()->live()->create();

    // When the public list is read (the handle lookup ignores case)
    $response = $this->getJson('/api/v1/users/AMINA_R/products');

    // Then only the two live ones appear, newest first, with the public keys (no maker card: the page is the maker's), counters and no has_voted for a guest
    $response->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.*.id'))->toBe([$newer->id, $older->id]);
    expect(array_keys($response->json('data.0')))->toBe(['id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at', 'votes_count', 'comments_count', 'topics']);
});

it('pages by keyset without skipping or repeating products launched at the same instant', function () {
    // Given five live products, three of which share one launched_at
    $maker = User::factory()->create(['handle' => 'tie_maker']);
    $tie = now()->subDays(2)->startOfSecond();
    foreach ([$tie, $tie, $tie, now()->subDay(), now()->subHours(2)] as $at) {
        Product::factory()->live()->create(['maker_id' => $maker->id, 'launched_at' => $at]);
    }

    // When read two at a time until the end
    $seen = [];
    $cursor = null;
    do {
        $page = $this->getJson('/api/v1/users/tie_maker/products?per_page=2'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    // Then each product appears exactly once
    expect($seen)->toHaveCount(5)->and(collect($seen)->unique()->count())->toBe(5);
});

it('has the index that matches the ordering', function () {
    // Given the migrated schema
    // When the definition of the member-list index is read
    $definition = DB::table('pg_indexes')->where('indexname', 'products_maker_id_launched_at_id_index')->value('indexdef');

    // Then it covers exactly the filter and the ordering of the list (maker, launch time, id)
    expect($definition)->toContain('(maker_id, launched_at, id)');
});

it('refuses tampered cursors and bad page sizes with 422', function (string $query) {
    // Given a member
    User::factory()->create(['handle' => 'amina_r']);

    // When the query string is hostile
    // Then it is a 422, never a 500
    $this->getJson('/api/v1/users/amina_r/products?'.$query)->assertUnprocessable();
})->with([
    'null cursor' => ['cursor=bnVsbA'], 'garbage' => ['cursor=!!!'], 'array cursor' => ['cursor[]=x'], 'object cursor' => ['cursor[a]=b'],
    'per_page 0' => ['per_page=0'], 'per_page 101' => ['per_page=101'], 'per_page text' => ['per_page=abc'], 'per_page array' => ['per_page[]=1'],
]);

it('answers an unknown, deleted or malformed handle with 404', function () {
    // Given a deleted member and a visible product of a normal one
    User::factory()->create(['handle' => 'gone_one', 'deleted_at' => now()]);

    // When the list is asked for them, for nobody and for a NUL byte handle
    // Then every answer is a 404
    $this->getJson('/api/v1/users/gone_one/products')->assertNotFound();
    $this->getJson('/api/v1/users/nobody/products')->assertNotFound();
    $this->getJson('/api/v1/users/a%00b/products')->assertNotFound();
    $this->getJson('/api/v1/users/a%00b')->assertNotFound();
});

it('returns an empty page for a member with no live products', function () {
    // Given a member with only a draft
    $maker = User::factory()->create(['handle' => 'quiet_one']);
    Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When listed
    // Then the data is empty and there is no next page
    $this->getJson('/api/v1/users/quiet_one/products')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.next_cursor', null);
});

it('does not expose live products of a deleted maker, and still lists those of a suspended one', function () {
    // Given a deleted maker and a suspended maker, each with a live product
    $deleted = User::factory()->create(['handle' => 'deleted_one', 'deleted_at' => now()]);
    Product::factory()->live()->create(['maker_id' => $deleted->id]);
    $suspended = User::factory()->suspended()->create(['handle' => 'suspended_one']);
    $product = Product::factory()->live()->create(['maker_id' => $suspended->id]);

    // When both lists are read
    // Then the deleted maker's handle is a 404, and the suspended maker's product stays listed (staff use take-down)
    $this->getJson('/api/v1/users/deleted_one/products')->assertNotFound();
    expect($this->getJson('/api/v1/users/suspended_one/products')->assertOk()->json('data.*.id'))->toBe([$product->id]);
});

it('carries the vote state of a known viewer on each card, not of a guest, and varies by credentials', function () {
    // Given a maker with two live products, one of which the viewer voted for
    $maker = User::factory()->create(['handle' => 'voted_maker']);
    $a = Product::factory()->live()->create(['maker_id' => $maker->id, 'launched_at' => now()->subDay(), 'votes_count' => 4, 'comments_count' => 2]);
    $b = Product::factory()->live()->create(['maker_id' => $maker->id, 'launched_at' => now()->subDays(2)]);
    $viewer = User::factory()->create();
    DB::table('votes')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $viewer->id, 'product_id' => $a->id, 'status' => 'counted', 'voted_at' => now(), 'toggle_count' => 0, 'created_at' => now()]);

    // When a guest and the viewer read the list
    $guest = $this->getJson('/api/v1/users/voted_maker/products')->assertOk();
    Sanctum::actingAs($viewer);
    $known = $this->getJson('/api/v1/users/voted_maker/products')->assertOk();

    // Then the guest sees counts only, the viewer sees has_voted per card, and the viewer's answer is never stored
    expect($guest->json('data.0'))->not->toHaveKey('has_voted')->and($guest->json('data.0.votes_count'))->toBe(4)->and($guest->json('data.0.comments_count'))->toBe(2);
    expect($known->json('data.*.has_voted'))->toBe([true, false]);
    expect($known->headers->get('Cache-Control'))->toContain('no-store')->and($known->headers->get('Vary'))->toContain('Authorization');
    expect($guest->headers->get('Vary'))->toContain('Cookie');
});

it('answers a page of cards in a constant number of queries, signed in or not', function () {
    // Given a maker with 2 live products and a signed-in viewer
    $maker = User::factory()->create(['handle' => 'n_plus_one']);
    Product::factory()->live()->count(2)->create(['maker_id' => $maker->id]);
    Sanctum::actingAs(User::factory()->create());
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/api/v1/users/n_plus_one/products')->assertOk();

        return count(DB::getQueryLog());
    };
    $small = $count();

    // When 6 more products exist
    Product::factory()->live()->count(6)->create(['maker_id' => $maker->id]);

    // Then the count is the same
    expect($count())->toBe($small);
});
