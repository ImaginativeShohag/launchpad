<?php

use App\Http\Resources\Api\V1\PublicUserResource;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('shows a public profile to anyone, without private fields', function () {
    // Given a member with a headline and a link
    $user = User::factory()->create(['handle' => 'amina_r', 'name' => 'Amina Rahman', 'headline' => 'Maker', 'email' => 'secret@example.com']);
    $user->links()->create(['kind' => 'website', 'label' => null, 'url' => 'https://amina.dev', 'position' => 0]);

    // When an anonymous visitor opens the profile (handle lookup ignores case)
    $response = $this->getJson('/api/v1/users/AMINA_R');

    // Then they see public fields only
    $response->assertOk()
        ->assertJsonPath('data.handle', 'amina_r')
        ->assertJsonPath('data.name', 'Amina Rahman')
        ->assertJsonPath('data.headline', 'Maker')
        ->assertJsonPath('data.is_suspended', false)
        ->assertJsonPath('data.links.0.url', 'https://amina.dev')
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.role');
});

it('exposes exactly the documented public keys', function () {
    // Given a member with a link
    $user = User::factory()->create(['handle' => 'amina_r', 'pending_email' => 'new@example.com']);
    $user->links()->create(['kind' => 'website', 'label' => null, 'url' => 'https://amina.dev', 'position' => 0]);

    // When the public profile is read
    $data = $this->getJson('/api/v1/users/amina_r')->assertOk()->json('data');

    // Then the member and link keys are exactly the documented ones (a guest gets the two counts, no is_following)
    expect(array_keys($data))->toEqualCanonicalizing(['id', 'handle', 'name', 'headline', 'avatar_url', 'links', 'is_suspended', 'joined_at', 'products_count', 'followers_count', 'following_count']);
    expect(array_keys($data['links'][0]))->toEqualCanonicalizing(['id', 'kind', 'label', 'url', 'position']);
    expect(PublicUserResource::class)->toBeString();
});

it('adds only is_following for a signed-in viewer', function () {
    // Given a signed-in member and a profile
    signedInMember();
    User::factory()->create(['handle' => 'amina_r']);

    // When they read it
    $data = $this->getJson('/api/v1/users/amina_r')->assertOk()->json('data');

    // Then the key list is the guest list plus is_following
    expect(array_keys($data))->toEqualCanonicalizing(['id', 'handle', 'name', 'headline', 'avatar_url', 'links', 'is_suspended', 'joined_at', 'products_count', 'followers_count', 'following_count', 'is_following']);
});

it('hides headline, links and avatar of a suspended member', function () {
    // Given a suspended member with an avatar and a link
    $user = User::factory()->suspended()->create(['handle' => 'spammer', 'headline' => 'Buy now', 'avatar_path' => 'avatars/x.webp']);
    $user->links()->create(['kind' => 'website', 'label' => null, 'url' => 'https://spam.example.com', 'position' => 0]);

    // When their profile is opened
    $response = $this->getJson('/api/v1/users/spammer');

    // Then it is flagged and stripped
    $response->assertOk()->assertJsonPath('data.is_suspended', true)->assertJsonPath('data.headline', null)
        ->assertJsonPath('data.links', [])->assertJsonPath('data.avatar_url', null);
});

it('answers 404 for unknown and deleted members', function () {
    // Given a deleted member
    User::factory()->create(['handle' => 'gone', 'deleted_at' => now()]);

    // When their profile and an unknown profile are requested
    // Then both are the same JSON 404
    $this->getJson('/api/v1/users/gone')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->getJson('/api/v1/users/nobody')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('answers 404, not 500, for handles with odd bytes', function (string $handle) {
    // Given any visitor
    // When they request a handle that is not valid text
    // Then it is the plain 404
    $this->getJson('/api/v1/users/'.$handle)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['nul byte' => ['a%00b'], 'unicode' => ['%C3%BC'], 'too long' => [str_repeat('a', 300)]]);

it('answers 400 for invalid UTF-8 in the handle', function () {
    // Given any visitor
    // When they request a handle with invalid UTF-8 bytes
    $response = $this->getJson('/api/v1/users/%FF%FE');

    // Then Symfony rejects the malformed request path itself (a 400, never a 500)
    $response->assertStatus(400);
});

it('counts only the publicly listed products of the member in products_count, in a constant number of queries', function () {
    // Given a member with two live products and hidden ones, and another member's live product
    $maker = User::factory()->create(['handle' => 'counted_one']);
    Product::factory()->live()->count(2)->create(['maker_id' => $maker->id]);
    Product::factory()->pending()->create(['maker_id' => $maker->id]);
    Product::factory()->scheduled()->create(['maker_id' => $maker->id]);
    Product::factory()->takenDown()->create(['maker_id' => $maker->id]);
    Product::factory()->live()->create(['maker_id' => $maker->id, 'deleted_at' => now()]);
    Product::factory()->live()->create();

    // When the profile is read as a guest
    DB::enableQueryLog();
    $response = $this->getJson('/api/v1/users/counted_one')->assertOk();
    $small = count(DB::getQueryLog());
    Product::factory()->live()->count(5)->create(['maker_id' => $maker->id]);
    DB::flushQueryLog();
    $again = $this->getJson('/api/v1/users/counted_one')->assertOk();

    // Then the count matches the public list and the query count did not grow
    expect($response->json('data.products_count'))->toBe(2)->and($again->json('data.products_count'))->toBe(7);
    expect(count(DB::getQueryLog()))->toBe($small);
});

it('does not put products_count on the maker card of a product read', function () {
    // Given a live product
    $product = Product::factory()->live()->create();

    // When read
    // Then the embedded maker has no count
    expect($this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.maker'))->not->toHaveKey('products_count');
});
