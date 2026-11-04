<?php

use App\Actions\Product\CreateDraft;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

it('creates a draft with a name and a slug', function () {
    // Given a confirmed member
    $maker = signedInMember();

    // When they create a draft
    $response = $this->postJson('/api/v1/products', ['name' => 'Launchpad App', 'slug' => 'launchpad-app']);

    // Then a draft owned by them exists, every other field empty, and the response lists what may be edited
    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.slug', 'launchpad-app')
        ->assertJsonPath('data.tagline', null)
        ->assertJsonPath('data.logo_url', null)
        ->assertJsonPath('data.launch_date', null)
        ->assertJsonPath('data.editable_fields.0', 'name')
        ->assertJsonPath('data.screenshots', []);
    $product = Product::query()->firstOrFail();
    expect($product->maker_id)->toBe($maker->id)->and($product->status->value)->toBe('draft');
});

it('exposes exactly the documented founder keys', function () {
    // Given a draft just created
    signedInMember();

    // When read back from the create response
    $keys = array_keys($this->postJson('/api/v1/products', ['name' => 'Keys App', 'slug' => 'keys-app'])->json('data'));

    // Then the resource has these keys and no internal ones (no counters, no search vector)
    expect($keys)->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'status', 'launch_date',
        'submitted_at', 'approved_at', 'launched_at', 'taken_down_at', 'takedown_reason', 'editable_fields',
        'media_editable', 'links', 'topics', 'screenshots', 'reviews', 'created_at', 'updated_at',
    ]);
});

it('lowercases and trims the slug and cleans hidden characters from the name', function () {
    // Given a member
    signedInMember();

    // When they send a padded mixed-case slug and a name with a bidi override
    $this->postJson('/api/v1/products', ['name' => "  Cool\u{202E} App  ", 'slug' => '  Cool-App  '])->assertCreated();

    // Then both are normalised
    $product = Product::query()->firstOrFail();
    expect($product->slug)->toBe('cool-app')->and($product->name)->toBe('Cool App');
});

it('refuses bad, reserved and taken slugs and unusable names', function (array $payload, string $field) {
    // Given a member and a product that owns a slug
    signedInMember();
    Product::factory()->create(['slug' => 'taken-slug']);

    // When the payload is wrong
    $response = $this->postJson('/api/v1/products', array_merge(['name' => 'Fine Name', 'slug' => 'fine-slug'], $payload));

    // Then it is a 422 on that field and nothing is created
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Product::query()->where('slug', 'fine-slug')->count())->toBe(0);
})->with([
    'slug too short' => [['slug' => 'ab'], 'slug'],
    'slug space' => [['slug' => 'my app'], 'slug'],
    'slug reserved' => [['slug' => 'admin'], 'slug'],
    'slug deleted prefix' => [['slug' => 'deleted-x1'], 'slug'],
    'slug taken' => [['slug' => 'taken-slug'], 'slug'],
    'slug taken other case' => [['slug' => 'TAKEN-slug'], 'slug'],
    'name without letters' => [['name' => '!!!'], 'name'],
    'name only invisible' => [['name' => "\u{200B}\u{202E}"], 'name'],
    'name too long' => [['name' => str_repeat('a', 61)], 'name'],
]);

it('answers wrong-shaped name and slug with 422', function (string $field, mixed $value) {
    // Given a member
    signedInMember();

    // When a field has the wrong shape
    $response = $this->postJson('/api/v1/products', array_merge(['name' => 'Fine Name', 'slug' => 'fine-slug'], [$field => $value]));

    // Then it is a 422, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Product::query()->count())->toBe(0);
})->with(function () {
    $cases = [];
    foreach (['name', 'slug'] as $field) {
        foreach (['array' => ['x'], 'empty array' => [], 'object' => ['a' => 'b'], 'integer' => 123, 'true' => true, 'false' => false, 'null' => null, 'empty' => ''] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }

    return $cases;
});

it('rejects a missing name or slug', function () {
    // Given a member
    signedInMember();

    // When fields are missing
    // Then both are required
    $this->postJson('/api/v1/products', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug']);
});

it('turns a slug race into a 422 on slug, not a 500', function () {
    // Given a slug taken between validation and insert (the action is called directly, skipping the request rule)
    $maker = User::factory()->create();
    Product::factory()->create(['slug' => 'raced-slug']);

    // When the action inserts the same slug
    $call = fn () => app(CreateDraft::class)->handle($maker, ['name' => 'Raced', 'slug' => 'raced-slug']);

    // Then a validation error on slug is thrown, not a query exception
    expect($call)->toThrow(ValidationException::class);
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given nobody is signed in
    // When a draft is created
    // Then it is 401
    $this->postJson('/api/v1/products', ['name' => 'X Y', 'slug' => 'x-y-app'])->assertUnauthorized();

    // And an unconfirmed member is told to confirm; a suspended one is blocked
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson('/api/v1/products', ['name' => 'X Y', 'slug' => 'x-y-app'])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->postJson('/api/v1/products', ['name' => 'X Y', 'slug' => 'x-y-app'])->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('refuses to create a draft for a deleted or suspended maker, re-checked under a share lock on the user row', function (string $state) {
    // Given a maker whose request passed the middleware, then was deleted or suspended; the action holds a stale, active model
    $maker = User::factory()->create();
    $stale = User::query()->findOrFail($maker->id);
    DB::table('users')->where('id', $maker->id)->update($state === 'deleted' ? ['deleted_at' => now()] : ['suspended_at' => now()]);

    // When the action runs with the stale model
    $call = fn () => app(CreateDraft::class)->handle($stale, ['name' => 'Ghost', 'slug' => 'ghost']);

    // Then it is refused with maker_unavailable and no draft appears
    expect($call)->toThrow(ProductRuleViolation::class);
    expect(Product::withTrashed()->count())->toBe(0);
})->with(['deleted', 'suspended']);

it('share-locks the maker user row when creating a draft', function () {
    // Given a maker and a recorder of locking queries
    $maker = User::factory()->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'for share') || str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    // When a draft is created
    app(CreateDraft::class)->handle($maker, ['name' => 'Locked', 'slug' => 'locked']);

    // Then the only lock is a share lock on the users table
    expect($locks)->toHaveCount(1)->and($locks[0])->toContain('"users"')->toContain('for share');
});
