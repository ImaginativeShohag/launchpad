<?php

use App\Actions\Product\UpdateProduct;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

function ownDraft(array $attributes = []): Product
{
    $maker = signedInMember();

    return Product::factory()->create(array_merge(['maker_id' => $maker->id], $attributes));
}

it('saves any subset of fields, repeatedly, and keeps the rest', function () {
    // Given a draft
    $product = ownDraft(['name' => 'Original', 'tagline' => 'Keep me']);

    // When two partial saves arrive one after the other
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    $response = $this->patchJson("/api/v1/products/{$product->id}", ['description' => "Line one\n\n\n\nLine two"]);

    // Then each save is applied, untouched fields stay, and newlines survive as at most one blank line
    $response->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.tagline', 'Keep me');
    expect($product->fresh()->description)->toBe("Line one\n\nLine two");
});

it('saves every field at once, including links and topics', function () {
    // Given a draft and two topics
    $product = ownDraft();
    $topics = Topic::factory()->count(2)->create();

    // When a full save arrives
    $response = $this->patchJson("/api/v1/products/{$product->id}", [
        'name' => 'Full App', 'slug' => 'full-app', 'tagline' => 'Does it all', 'description' => 'Long text',
        'website_url' => 'HTTPS://Full-App.dev/Docs', 'topic_ids' => $topics->pluck('id')->all(),
        'links' => [['kind' => 'github', 'url' => 'https://github.com/full/app'], ['kind' => 'docs', 'url' => 'https://docs.full-app.dev']],
    ]);

    // Then everything is stored; the URL host is lowercased but its path keeps case; links keep their order
    $response->assertOk()->assertJsonPath('data.slug', 'full-app')->assertJsonPath('data.website_url', 'https://full-app.dev/Docs');
    expect($response->json('data.links.0.kind'))->toBe('github')->and($response->json('data.links.1.position'))->toBe(1);
    expect($response->json('data.topics'))->toHaveCount(2);
    expect($product->fresh()->topics()->count())->toBe(2);
});

it('replaces links wholesale and clears them with an empty list', function () {
    // Given a draft with two links
    $product = ownDraft();
    $this->patchJson("/api/v1/products/{$product->id}", ['links' => [
        ['kind' => 'github', 'url' => 'https://github.com/a/b'], ['kind' => 'x', 'url' => 'https://x.com/a'],
    ]])->assertOk();

    // When one link is sent, then an empty list
    $one = $this->patchJson("/api/v1/products/{$product->id}", ['links' => [['kind' => 'docs', 'url' => 'https://docs.example.org']]]);
    $none = $this->patchJson("/api/v1/products/{$product->id}", ['links' => []]);

    // Then the list is replaced each time
    $one->assertOk()->assertJsonCount(1, 'data.links');
    $none->assertOk()->assertJsonCount(0, 'data.links');
});

it('refuses bad links, topics and fields', function (array $payload, string $field) {
    // Given a draft, a topic and the max_topics setting at 2
    $product = ownDraft();
    $topic = Topic::factory()->create();
    app(Settings::class)->set(SettingKey::ProductMaxTopics, 2, User::factory()->role(Role::Admin)->create());
    $payload = json_decode(str_replace('TOPIC', $topic->id, json_encode($payload)), true);

    // When the payload is wrong
    $response = $this->patchJson("/api/v1/products/{$product->id}", $payload);

    // Then it is a 422 on the field and nothing is saved
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($product->fresh()->name)->toBe($product->name);
})->with([
    'website link kind' => [['links' => [['kind' => 'website', 'url' => 'https://a.dev']]], 'links.0.kind'],
    'unknown kind' => [['links' => [['kind' => 'myspace', 'url' => 'https://a.dev']]], 'links.0.kind'],
    'http link' => [['links' => [['kind' => 'docs', 'url' => 'http://a.dev']]], 'links.0.url'],
    'localhost link' => [['links' => [['kind' => 'docs', 'url' => 'https://localhost']]], 'links.0.url'],
    'ip link' => [['links' => [['kind' => 'docs', 'url' => 'https://127.0.0.1']]], 'links.0.url'],
    'six links' => [['links' => array_fill(0, 6, ['kind' => 'docs', 'url' => 'https://a.dev'])], 'links'],
    'links as object' => [['links' => ['a' => ['kind' => 'docs', 'url' => 'https://a.dev']]], 'links'],
    'link as string' => [['links' => ['https://a.dev']], 'links.0'],
    'http website' => [['website_url' => 'http://a.dev'], 'website_url'],
    'private website' => [['website_url' => 'https://192.168.0.1'], 'website_url'],
    'tagline too long' => [['tagline' => str_repeat('a', 81)], 'tagline'],
    'description too long' => [['description' => str_repeat('a', 5001)], 'description'],
    'name empty' => [['name' => ''], 'name'],
    'unknown topic' => [['topic_ids' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ']], 'topic_ids'],
    'not a ulid' => [['topic_ids' => ['nope']], 'topic_ids.0'],
    'duplicate topic' => [['topic_ids' => ['TOPIC', 'TOPIC']], 'topic_ids.0'],
    'topics over the setting' => [['topic_ids' => ['TOPIC', '01JZZZZZZZZZZZZZZZZZZZZZZA', '01JZZZZZZZZZZZZZZZZZZZZZZB']], 'topic_ids'],
]);

it('answers wrong-shaped values with 422 for every field', function (string $field, mixed $value) {
    // Given a draft
    $product = ownDraft();

    // When a field has the wrong shape
    $response = $this->patchJson("/api/v1/products/{$product->id}", [$field => $value]);

    // Then it is a validation error on that field (or one of its items), never a 500
    $response->assertUnprocessable();
    expect(collect($response->json('errors'))->keys()->contains(fn (string $key) => str_starts_with($key, $field)))->toBeTrue();
})->with(function () {
    $cases = [];
    foreach (['name', 'slug', 'tagline', 'description', 'website_url'] as $field) {
        foreach (['array' => ['x'], 'empty array' => [], 'object' => ['a' => 'b'], 'integer' => 123, 'true' => true, 'false' => false] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }
    foreach (['name', 'slug'] as $field) {
        $cases["$field null"] = [$field, null];
        $cases["$field empty"] = [$field, ''];
    }
    foreach (['links', 'topic_ids', 'screenshots'] as $field) {
        foreach (['string' => 'x', 'integer' => 5, 'true' => true, 'object' => ['a' => 'b'], 'null' => null] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }
    $cases['link url array'] = ['links', [['kind' => 'docs', 'url' => ['x']]]];
    $cases['link kind array'] = ['links', [['kind' => ['x'], 'url' => 'https://a.dev']]];
    $cases['topic nested'] = ['topic_ids', [['x']]];
    $cases['topic int'] = ['topic_ids', [123]];
    $cases['screenshot id array'] = ['screenshots', [['id' => ['x']]]];
    $cases['screenshot alt array'] = ['screenshots', [['id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'alt_text' => ['x']]]];

    return $cases;
});

it('clears optional text with null', function () {
    // Given a draft with a tagline and website
    $product = ownDraft(['tagline' => 'Old', 'website_url' => 'https://old.dev']);

    // When null is sent
    $this->patchJson("/api/v1/products/{$product->id}", ['tagline' => null, 'website_url' => null, 'description' => '  ']);

    // Then they are cleared
    $fresh = $product->fresh();
    expect($fresh->tagline)->toBeNull()->and($fresh->website_url)->toBeNull()->and($fresh->description)->toBeNull();
});

it('lets the maker change the slug of a draft, to a free one only', function () {
    // Given a draft and another product that owns a slug
    $product = ownDraft(['slug' => 'old-slug']);
    Product::factory()->create(['slug' => 'other-slug']);

    // When the slug changes to a free one, to the same one, and to a taken one
    $free = $this->patchJson("/api/v1/products/{$product->id}", ['slug' => 'new-slug']);
    $same = $this->patchJson("/api/v1/products/{$product->id}", ['slug' => 'new-slug']);
    $taken = $this->patchJson("/api/v1/products/{$product->id}", ['slug' => 'other-slug']);

    // Then only the taken one is refused
    $free->assertOk()->assertJsonPath('data.slug', 'new-slug');
    $same->assertOk();
    $taken->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('updates the alt text of the product screenshots and refuses foreign ids', function () {
    // Given a draft with a screenshot, and another product's screenshot
    $product = ownDraft();
    $shot = $product->screenshots()->create(['path' => 'products/screenshots/a.webp', 'position' => 0, 'width' => 800, 'height' => 600]);
    $foreign = Product::factory()->complete()->create()->screenshots()->firstOrFail();

    // When alt text is saved for its own screenshot, then for a foreign one
    $ok = $this->patchJson("/api/v1/products/{$product->id}", ['screenshots' => [['id' => $shot->id, 'alt_text' => '  Dashboard view ']]]);
    $bad = $this->patchJson("/api/v1/products/{$product->id}", ['screenshots' => [['id' => $foreign->id, 'alt_text' => 'x']]]);

    // Then the first is stored cleaned and the second is a 422 on that item
    $ok->assertOk()->assertJsonPath('data.screenshots.0.alt_text', 'Dashboard view');
    $bad->assertUnprocessable()->assertJsonValidationErrors('screenshots.0.id');
    expect($foreign->fresh()->alt_text)->toBeNull();
});

it('accepts an empty body without changing anything', function () {
    // Given a draft
    $product = ownDraft();

    // When nothing is sent
    // Then it succeeds
    $this->patchJson("/api/v1/products/{$product->id}", [])->assertOk()->assertJsonPath('data.name', $product->name);
});

it('answers another member, staff and a missing product with the identical 404', function () {
    // Given someone else's draft, and an admin
    $foreign = Product::factory()->create();
    $unknown = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
    signedInMember();

    // When a member patches it, and an admin patches it, and an unknown id is patched
    $asMember = $this->patchJson("/api/v1/products/{$foreign->id}", ['name' => 'Hijacked']);
    $missing = $this->patchJson("/api/v1/products/{$unknown}", ['name' => 'Hijacked']);
    signedInStaff(Role::Admin);
    $asAdmin = $this->patchJson("/api/v1/products/{$foreign->id}", ['name' => 'Hijacked']);

    // Then all three are the same 404 and nothing changed
    foreach ([$asMember, $missing, $asAdmin] as $response) {
        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
    expect($foreign->fresh()->name)->not->toBe('Hijacked');
});

it('authorises before it validates', function () {
    // Given someone else's draft
    $foreign = Product::factory()->create();
    signedInMember();

    // When a non-owner sends an invalid payload
    $response = $this->patchJson("/api/v1/products/{$foreign->id}", ['name' => ['x'], 'slug' => 'BAD SLUG']);

    // Then they learn nothing about the rules: 404, not 422
    $response->assertNotFound();
});

it('treats a soft-deleted draft as missing', function () {
    // Given a deleted draft of the signed-in member
    $product = ownDraft(['deleted_at' => now()]);

    // When it is patched
    // Then it is a 404
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Back'])->assertNotFound();
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a draft
    $product = Product::factory()->create();

    // When nobody is signed in, then an unconfirmed member, then a suspended one
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'X Y'])->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'X Y'])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'X Y'])->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('turns a slug race on update into a 422 on slug', function () {
    // Given a draft and a slug taken by another product meanwhile
    $maker = User::factory()->create();
    $product = Product::factory()->create(['maker_id' => $maker->id]);
    Product::factory()->create(['slug' => 'raced-slug']);

    // When the action runs directly with that slug
    $call = fn () => app(UpdateProduct::class)->handle($maker, $product, ['slug' => 'raced-slug']);

    // Then a validation error is thrown
    expect($call)->toThrow(ValidationException::class);
});

it('share-locks the topics it attaches before writing the pivot, so a topic delete cannot interleave', function () {
    // Given a draft, a topic and a recorder of every locking query
    $maker = User::factory()->create();
    $product = Product::factory()->create(['maker_id' => $maker->id]);
    $topic = Topic::factory()->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'for update') || str_contains($query->sql, 'for share')) {
            $locks[] = $query->sql;
        }
    });

    // When topics are attached
    app(UpdateProduct::class)->handle($maker, $product, ['topic_ids' => [$topic->id]]);

    // Then the product row is locked first, then the topics are share-locked
    expect($locks[0])->toContain('"products"')->and($locks[0])->toContain('for update');
    expect($locks[1])->toContain('"topics"')->and($locks[1])->toContain('for share');
});

it('answers 422, not a foreign-key 500, when a chosen topic was deleted after validation', function () {
    // Given a draft and a topic that was deleted between validation and the write (the action is called directly)
    $maker = User::factory()->create();
    $product = Product::factory()->create(['maker_id' => $maker->id]);
    $kept = Topic::factory()->create();
    $gone = Topic::factory()->create();
    $gone->delete();

    // When the action attaches both
    $call = fn () => app(UpdateProduct::class)->handle($maker, $product, ['topic_ids' => [$kept->id, $gone->id]]);

    // Then a validation error on topic_ids is thrown and nothing is attached
    expect($call)->toThrow(ValidationException::class);
    expect($product->topics()->count())->toBe(0);
});

it('locks several topics in id order', function () {
    // Given a draft and three topics sent in reverse order
    $maker = User::factory()->create();
    $product = Product::factory()->create(['maker_id' => $maker->id]);
    $ids = Topic::factory()->count(3)->create()->pluck('id')->sortDesc()->values()->all();
    $sql = null;
    DB::listen(function ($query) use (&$sql) {
        if (str_contains($query->sql, '"topics"') && str_contains($query->sql, 'for share')) {
            $sql = $query->sql;
        }
    });

    // When they are attached
    app(UpdateProduct::class)->handle($maker, $product, ['topic_ids' => $ids]);

    // Then the lock query orders by id
    expect($sql)->toContain('order by "id" asc');
});

it('refuses, inside the action, a product that belongs to someone else', function () {
    // Given a product and a different actor (the action is called directly, skipping the policy)
    $product = Product::factory()->create(['name' => 'Mine']);
    $stranger = User::factory()->create();

    // When the stranger's id reaches the action
    $call = fn () => app(UpdateProduct::class)->handle($stranger, $product, ['name' => 'Taken Over']);

    // Then it is "not found" and nothing changes
    expect($call)->toThrow(ModelNotFoundException::class);
    expect($product->fresh()->name)->toBe('Mine');
});

it('keeps the stored alt text when a screenshot item omits alt_text', function () {
    // Given a screenshot with alt text
    $product = ownDraft();
    $shot = $product->screenshots()->create(['path' => 'products/screenshots/a.webp', 'position' => 0, 'width' => 800, 'height' => 600, 'alt_text' => 'Dashboard']);

    // When the item is sent without alt_text
    $response = $this->patchJson("/api/v1/products/{$product->id}", ['screenshots' => [['id' => $shot->id]]]);

    // Then the stored text stays
    $response->assertOk();
    expect($shot->fresh()->alt_text)->toBe('Dashboard');
});

it('ignores server-owned fields in the body', function () {
    // Given a draft
    $product = ownDraft();
    $other = User::factory()->create();

    // When the body also carries status, owner, counters and a logo path
    $response = $this->patchJson("/api/v1/products/{$product->id}", [
        'name' => 'Fine Name', 'status' => 'live', 'maker_id' => $other->id, 'votes_count' => 99, 'logo_path' => 'x.webp', 'launch_date' => '2030-01-01',
    ]);

    // Then only the name is saved
    $response->assertOk();
    $fresh = $product->fresh();
    expect($fresh->name)->toBe('Fine Name')
        ->and($fresh->status->value)->toBe('draft')
        ->and($fresh->maker_id)->toBe($product->maker_id)
        ->and($fresh->votes_count)->toBe(0)
        ->and($fresh->logo_path)->not->toBe('x.webp')
        ->and($fresh->launch_date)->toBeNull();
});

it('writes nothing when a later part of the same patch is refused', function () {
    // Given a draft with one link
    $product = ownDraft();
    $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/a/b', 'position' => 0]);

    // When a patch replaces the links but names a topic that does not exist
    $response = $this->patchJson("/api/v1/products/{$product->id}", [
        'links' => [['kind' => 'docs', 'url' => 'https://docs.example.com']],
        'topic_ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ]);

    // Then it is a 422 and the old link is still there
    $response->assertUnprocessable()->assertJsonValidationErrors('topic_ids');
    expect($product->links()->pluck('url')->all())->toBe(['https://github.com/a/b']);
});
