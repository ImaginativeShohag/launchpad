<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Product;

afterEach(fn () => AuditLog::flushEventListeners());

function liveOf(array $attributes = []): Product
{
    return Product::factory()->live()->create(array_merge(['maker_id' => signedInMember()->id], $attributes));
}

it('lets the maker change description, website and links of a live product at once, and audits it', function () {
    // Given a live product with a link
    $product = liveOf(['description' => 'Old description', 'website_url' => 'https://old.example.org']);
    $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/old/repo', 'position' => 0]);

    // When the maker edits all three
    $response = $this->patchJson("/api/v1/products/{$product->id}", [
        'description' => 'New description',
        'website_url' => 'https://new.example.org',
        'links' => [['kind' => 'docs', 'url' => 'https://docs.example.org']],
    ]);

    // Then the change is live immediately and one audit row holds field names with before and after
    $response->assertOk()->assertJsonPath('data.description', 'New description')->assertJsonPath('data.editable_fields', ['description', 'website_url', 'links']);
    $fresh = Product::query()->findOrFail($product->id);
    expect($fresh->description)->toBe('New description')->and($fresh->website_url)->toBe('https://new.example.org');
    expect(Product::visible()->whereKey($product->id)->exists())->toBeTrue();
    $log = AuditLog::query()->where('action', 'product.edited_after_launch')->firstOrFail();
    expect($log->actor_id)->toBe($product->maker_id)->and($log->subject_id)->toBe($product->id);
    expect($log->meta)->toEqual([
        'product_id' => $product->id,
        'fields' => ['description', 'website_url', 'links'],
        'before' => ['description' => 'Old description', 'website_url' => 'https://old.example.org', 'links' => [['kind' => 'github', 'url' => 'https://github.com/old/repo']]],
        'after' => ['description' => 'New description', 'website_url' => 'https://new.example.org', 'links' => [['kind' => 'docs', 'url' => 'https://docs.example.org']]],
    ]);
});

it('audits only the fields that actually changed and nothing when nothing changed', function () {
    // Given a live product
    $product = liveOf(['description' => 'Same text', 'website_url' => 'https://same.example.org']);

    // When only the description changes, then the same values are sent again
    $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Different text'])->assertOk();
    $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Different text', 'website_url' => 'https://same.example.org'])->assertOk();

    // Then exactly one audit row exists and it names only the description
    $logs = AuditLog::query()->where('action', 'product.edited_after_launch')->get();
    expect($logs)->toHaveCount(1);
    expect($logs[0]->meta)->toEqual(['product_id' => $product->id, 'fields' => ['description'], 'before' => ['description' => 'Same text'], 'after' => ['description' => 'Different text']]);
});

it('keeps member data out of the audit meta', function () {
    // Given a live product whose maker has a recognisable name, handle and email
    $maker = signedInMember(['name' => 'Zainab Karim', 'handle' => 'zainab_k', 'email' => 'zainab@example.com']);
    $product = Product::factory()->live()->create(['maker_id' => $maker->id]);

    // When the description changes
    $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Fresh words'])->assertOk();

    // Then the audit meta mentions none of them
    $meta = json_encode(AuditLog::query()->where('action', 'product.edited_after_launch')->firstOrFail()->meta);
    expect($meta)->not->toContain('Zainab')->not->toContain('zainab_k')->not->toContain('zainab@example.com');
});

it('refuses the fields that are frozen after launch, and applies none of the request', function (string $field, mixed $value) {
    // Given a live product
    $product = liveOf(['description' => 'Untouched']);

    // When a frozen field is sent together with a valid description change
    $response = $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Changed', $field => $value]);

    // Then it is a 422 on that field, the description is not saved either, and nothing is audited
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($product->fresh()->description)->toBe('Untouched');
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'name' => ['name', 'Renamed'], 'slug' => ['slug', 'renamed-slug'], 'tagline' => ['tagline', 'New tagline'],
    'topics' => ['topic_ids', []], 'screenshots' => ['screenshots', []],
]);

it('does not let a live product lose its description or website', function (string $field, mixed $value) {
    // Given a live product
    $product = liveOf(['description' => 'Keep me', 'website_url' => 'https://keep.example.org']);

    // When the maker tries to blank a field every live product needs
    $response = $this->patchJson("/api/v1/products/{$product->id}", [$field => $value]);

    // Then it is a 422 and the value stays
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($product->fresh()->{$field})->toBe($field === 'description' ? 'Keep me' : 'https://keep.example.org');
    expect(AuditLog::query()->count())->toBe(0);
})->with([['description', null], ['description', '  '], ['website_url', null]]);

it('applies the same link and url rules as for a draft', function (array $payload, string $field) {
    // Given a live product
    $product = liveOf();

    // When the payload breaks a rule
    $response = $this->patchJson("/api/v1/products/{$product->id}", $payload);

    // Then it is a 422
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'website kind' => [['links' => [['kind' => 'website', 'url' => 'https://a.dev']]], 'links.0.kind'],
    'http link' => [['links' => [['kind' => 'docs', 'url' => 'http://a.dev']]], 'links.0.url'],
    'six links' => [['links' => array_fill(0, 6, ['kind' => 'docs', 'url' => 'https://a.dev'])], 'links'],
    'private website' => [['website_url' => 'https://10.0.0.1'], 'website_url'],
]);

it('answers wrong-shaped description, website and links with 422', function (string $field, mixed $value) {
    // Given a live product
    $product = liveOf();

    // When a field has the wrong shape
    $response = $this->patchJson("/api/v1/products/{$product->id}", [$field => $value]);

    // Then it is a 422 on that field, never a 500
    $response->assertUnprocessable();
    expect(collect($response->json('errors'))->keys()->contains(fn (string $key) => str_starts_with($key, $field)))->toBeTrue();
})->with(function () {
    $cases = [];
    foreach (['description', 'website_url'] as $field) {
        foreach (['array' => ['x'], 'empty array' => [], 'object' => ['a' => 'b'], 'integer' => 123, 'true' => true] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }
    foreach (['string' => 'x', 'true' => true, 'object' => ['a' => 'b'], 'null' => null] as $label => $value) {
        $cases["links $label"] = ['links', $value];
    }
    $cases['link url array'] = ['links', [['kind' => 'docs', 'url' => ['x']]]];

    return $cases;
});

it('rolls the edit back when the audit row cannot be written, because the audit is part of the edit', function () {
    // Given a live product and an audit insert that fails
    $product = liveOf(['description' => 'Original']);
    $link = $product->links()->create(['kind' => 'docs', 'url' => 'https://docs.example.org/old', 'position' => 0]);
    AuditLog::creating(fn () => throw new RuntimeException('audit down'));

    // When the maker edits the description and the links
    $response = $this->patchJson("/api/v1/products/{$product->id}", [
        'description' => 'Edited', 'links' => [['kind' => 'docs', 'url' => 'https://docs.example.org/new']],
    ]);

    // Then the request fails, the description is unchanged and the old link row (same id) survived
    $response->assertStatus(500);
    expect($product->fresh()->description)->toBe('Original');
    expect($product->links()->pluck('url', 'id')->all())->toBe([$link->id => 'https://docs.example.org/old']);
    expect(AuditLog::query()->count())->toBe(0);
});

it('writes no audit row when identical links are sent again, even though their ids change', function () {
    // Given a live product with two links
    $product = liveOf();
    $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/a/b', 'position' => 0]);
    $product->links()->create(['kind' => 'docs', 'url' => 'https://docs.example.org', 'position' => 1]);
    $oldIds = $product->links()->pluck('id')->all();

    // When the same links are sent again
    $this->patchJson("/api/v1/products/{$product->id}", ['links' => [
        ['kind' => 'github', 'url' => 'https://github.com/a/b'],
        ['kind' => 'docs', 'url' => 'https://docs.example.org'],
    ]])->assertOk();

    // Then the rows were rewritten but nothing is audited
    expect($product->links()->pluck('id')->all())->not->toEqual($oldIds);
    expect(AuditLog::query()->where('action', 'product.edited_after_launch')->count())->toBe(0);
});

it('audits a reordering of links as a change', function () {
    // Given a live product with two links
    $product = liveOf();
    $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/a/b', 'position' => 0]);
    $product->links()->create(['kind' => 'docs', 'url' => 'https://docs.example.org', 'position' => 1]);

    // When the links are sent in the other order
    $this->patchJson("/api/v1/products/{$product->id}", ['links' => [
        ['kind' => 'docs', 'url' => 'https://docs.example.org'],
        ['kind' => 'github', 'url' => 'https://github.com/a/b'],
    ]])->assertOk();

    // Then one audit row names the links
    $log = AuditLog::query()->where('action', 'product.edited_after_launch')->firstOrFail();
    expect($log->meta['fields'])->toBe(['links']);
});

it('does not let reviewers or admins edit a founder product', function (Role $role) {
    // Given a live product and a staff member who is not its maker
    $product = Product::factory()->live()->create(['description' => 'Founder text']);
    signedInStaff($role);

    // When they try to edit it
    $response = $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Staff text']);

    // Then it is the uniform 404 and nothing changes
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($product->fresh()->description)->toBe('Founder text');
})->with([[Role::Reviewer], [Role::Admin]]);

it('shows exactly what each status allows (the edit-rule matrix)', function (string $state, int $status, array $payload) {
    // Given the maker's product in a status
    $product = Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id]);

    // When the matching payload is sent
    $response = $this->patchJson("/api/v1/products/{$product->id}", $payload);

    // Then the answer matches the spec's table
    $response->assertStatus($status);
})->with([
    'draft: everything' => ['complete', 200, ['name' => 'New Name', 'description' => 'x']],
    'changes requested: everything' => ['changesRequested', 200, ['name' => 'New Name', 'description' => 'x']],
    'pending: nothing' => ['pending', 403, ['description' => 'x']],
    'approved: nothing (only the launch day)' => ['approved', 403, ['description' => 'x']],
    'scheduled: nothing (only the launch day)' => ['scheduled', 403, ['description' => 'x']],
    'live: description' => ['live', 200, ['description' => 'x']],
    'live: name is frozen' => ['live', 422, ['name' => 'New Name']],
    'rejected: read-only' => ['rejected', 403, ['description' => 'x']],
    'taken down: read-only' => ['takenDown', 403, ['description' => 'x']],
]);

it('writes nothing for an empty body and audits an emptied link list', function () {
    // Given a live product with one link
    $product = liveOf();
    $product->links()->create(['kind' => 'docs', 'url' => 'https://docs.example.org', 'position' => 0]);

    // When an empty body is sent, then an empty link list
    $this->patchJson("/api/v1/products/{$product->id}", [])->assertOk();
    expect(AuditLog::query()->count())->toBe(0);
    $this->patchJson("/api/v1/products/{$product->id}", ['links' => []])->assertOk();

    // Then only the second one is audited, with an empty list after
    $log = AuditLog::query()->where('action', 'product.edited_after_launch')->firstOrFail();
    expect($log->meta['fields'])->toBe(['links'])->and($log->meta['after']['links'])->toBe([]);
});

it('accepts a 5000-character multibyte description on a live product and refuses 5001', function () {
    // Given a live product
    $product = liveOf();

    // When the longest description and one more character are sent
    $ok = $this->patchJson("/api/v1/products/{$product->id}", ['description' => str_repeat('é', 5000)]);
    $tooLong = $this->patchJson("/api/v1/products/{$product->id}", ['description' => str_repeat('é', 5001)]);

    // Then 5000 is saved and audited, and 5001 is a 422
    $ok->assertOk();
    $tooLong->assertUnprocessable()->assertJsonValidationErrors('description');
    expect(AuditLog::query()->where('action', 'product.edited_after_launch')->count())->toBe(1);
});

it('does not write the after-launch audit for a draft edit', function () {
    // Given a draft
    $product = Product::factory()->create(['maker_id' => signedInMember()->id]);

    // When it is edited
    $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Draft words'])->assertOk();

    // Then nothing is audited
    expect(AuditLog::query()->where('action', 'product.edited_after_launch')->count())->toBe(0);
});
