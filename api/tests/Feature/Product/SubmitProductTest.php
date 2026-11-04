<?php

use App\Actions\Product\SubmitProduct;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Events\ProductSubmitted;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

function submittable(string $state = 'complete', array $attributes = []): Product
{
    return Product::factory()->{$state}()->create(array_merge(['maker_id' => signedInMember()->id], $attributes));
}

it('moves a complete draft to pending, stamps submitted_at and fires an id-only event', function () {
    // Given a complete draft
    Event::fake([ProductSubmitted::class]);
    $product = submittable();

    // When the maker submits it
    $response = $this->postJson("/api/v1/products/{$product->id}/submit");

    // Then it is pending, timestamped, locked for editing, and the event carries ids only
    $response->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.editable_fields', []);
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::Pending)->and($fresh->submitted_at)->not->toBeNull();
    Event::assertDispatched(ProductSubmitted::class, fn (ProductSubmitted $e) => $e->productId === $product->id && $e->actorId === $product->maker_id);
});

it('lets a product with requested changes be submitted again and refreshes submitted_at', function () {
    // Given a changes_requested product that was submitted an hour ago
    $product = submittable('changesRequested');
    $before = $product->submitted_at;

    // When resubmitted
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertOk();

    // Then it is pending again with a newer timestamp
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::Pending)->and($fresh->submitted_at->gt($before))->toBeTrue();
});

it('lists every missing field at once and changes nothing', function () {
    // Given a bare draft: only name and slug
    Event::fake([ProductSubmitted::class]);
    $product = submittable('complete', ['tagline' => null, 'description' => null, 'website_url' => null, 'logo_path' => null]);
    $product->screenshots()->delete();
    DB::table('product_topics')->where('product_id', $product->id)->delete();

    // When the maker submits
    $response = $this->postJson("/api/v1/products/{$product->id}/submit");

    // Then one 422 names all six gaps
    $response->assertUnprocessable();
    $fields = array_keys($response->json('errors'));
    sort($fields);
    expect($fields)->toBe(['description', 'logo', 'screenshots', 'tagline', 'topic_ids', 'website_url']);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft)->and($product->fresh()->submitted_at)->toBeNull();
    Event::assertNotDispatched(ProductSubmitted::class);
});

it('refuses more topics than the product.max_topics setting', function () {
    // Given a complete draft with three topics and the limit lowered to two
    $product = submittable();
    foreach (Topic::factory()->count(2)->create() as $topic) {
        DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
    }
    app(Settings::class)->set(SettingKey::ProductMaxTopics, 2, User::factory()->role(Role::Admin)->create());

    // When submitted
    $response = $this->postJson("/api/v1/products/{$product->id}/submit");

    // Then topic_ids is the only complaint
    $response->assertUnprocessable()->assertJsonValidationErrors('topic_ids');
    expect(array_keys($response->json('errors')))->toBe(['topic_ids']);
});

it('refuses a submit from any other status with 409', function (string $state) {
    // Given a product in a status that cannot be submitted
    $product = submittable($state);

    // When submitted
    $response = $this->postJson("/api/v1/products/{$product->id}/submit");

    // Then it is a 409 with a stable code and nothing changed
    $response->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    expect($product->fresh()->status)->toBe($product->status);
})->with(['pending', 'approved', 'scheduled', 'live', 'rejected', 'takenDown']);

it('treats a second submit of the same product as a 409 and fires the event once', function () {
    // Given a complete draft
    Event::fake([ProductSubmitted::class]);
    $product = submittable();

    // When submitted twice
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertOk();
    $second = $this->postJson("/api/v1/products/{$product->id}/submit");

    // Then the second is refused and only one event fired
    $second->assertStatus(409);
    Event::assertDispatchedTimes(ProductSubmitted::class, 1);
});

it('re-checks the status on the locked row, not on the model it was handed', function () {
    // Given a stale copy of a draft that has meanwhile been submitted
    $maker = User::factory()->create();
    $product = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $stale = Product::query()->findOrFail($product->id);
    app(SubmitProduct::class)->handle($maker, $product);

    // When the stale copy is submitted again
    $again = fn () => app(SubmitProduct::class)->handle($maker, $stale);

    // Then the locked row says pending and the action refuses
    expect($again)->toThrow(ProductRuleViolation::class);
});

it('locks the product row before it counts topics and screenshots', function () {
    // Given a complete draft and a recorder of locking queries
    $maker = User::factory()->create();
    $product = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When submitted
    app(SubmitProduct::class)->handle($maker, $product);

    // Then the first query touching products is the FOR UPDATE, before any count
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, '"products"') && str_contains($sql, 'for update'));
    $count = collect($queries)->search(fn ($sql) => str_contains($sql, 'count('));
    expect($lock)->not->toBeFalse()->and($lock)->toBeLessThan($count);
});

it('ignores junk request bodies instead of failing', function (mixed $body) {
    // Given a complete draft
    $product = submittable();

    // When the (bodyless) endpoint receives junk
    $response = $this->postJson("/api/v1/products/{$product->id}/submit", $body);

    // Then the body is ignored: it is a normal 200, never a 500
    $response->assertOk();
})->with([[['x' => ['a']]], [['x' => ['a' => 'b']]], [['x' => null]], [[]]]);

it('answers another member and a missing product with the identical 404', function () {
    // Given someone else's complete draft
    $foreign = Product::factory()->complete()->create();
    signedInMember();

    // When a member submits it, and an unknown id
    $own = $this->postJson("/api/v1/products/{$foreign->id}/submit");
    $missing = $this->postJson('/api/v1/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/submit');

    // Then both are the same 404 and the draft is untouched
    $own->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $missing->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($foreign->fresh()->status)->toBe(ProductStatus::Draft);
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a draft
    $product = Product::factory()->complete()->create();

    // When nobody, an unconfirmed member and a suspended member submit
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('audits the submission and fires the event only after commit, never for a refused submit', function () {
    // Given a real listener (no fake), one complete draft and one incomplete draft
    $fired = 0;
    Event::listen(ProductSubmitted::class, function () use (&$fired) {
        $fired++;
    });
    $maker = signedInMember();
    $complete = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $incomplete = Product::factory()->create(['maker_id' => $maker->id, 'tagline' => null]);

    // When both are submitted
    $this->postJson("/api/v1/products/{$complete->id}/submit")->assertOk();
    $this->postJson("/api/v1/products/{$incomplete->id}/submit")->assertUnprocessable();

    // Then only the first is audited (with its previous status) and dispatched, and approved_at is cleared
    $audit = AuditLog::query()->where('action', 'product.submitted')->sole();
    expect($audit->meta)->toEqual(['from_status' => 'draft'])->and($audit->subject_id)->toBe($complete->id)->and($fired)->toBe(1);
    expect($complete->fresh()->approved_at)->toBeNull();
});

it('treats a soft-deleted draft as missing', function () {
    // Given a deleted draft
    $product = submittable();
    $product->delete();

    // When the maker submits it
    $response = $this->postJson("/api/v1/products/{$product->id}/submit");

    // Then it is a 404
    $response->assertNotFound();
});

function pendingCap(int $cap): void
{
    app(Settings::class)->set(SettingKey::ProductMaxPendingPerMaker, $cap, User::factory()->role(Role::Admin)->create());
}

it('refuses the submit that would go over the pending cap with 409 pending_limit and changes nothing', function () {
    // Given a maker with five pending products and a complete draft
    $maker = signedInMember();
    Product::factory()->pending()->count(5)->create(['maker_id' => $maker->id]);
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When they submit the draft
    $response = $this->postJson("/api/v1/products/{$draft->id}/submit");

    // Then it is refused, the draft stays a draft and nothing is audited
    $response->assertStatus(409)->assertJsonPath('code', 'pending_limit');
    expect($draft->fresh()->status)->toBe(ProductStatus::Draft)->and($draft->fresh()->submitted_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'product.submitted')->count())->toBe(0);
});

it('allows the submit that reaches the cap', function () {
    // Given a maker with four pending products and a complete draft
    $maker = signedInMember();
    Product::factory()->pending()->count(4)->create(['maker_id' => $maker->id]);
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When they submit the draft
    $response = $this->postJson("/api/v1/products/{$draft->id}/submit");

    // Then it is the fifth pending product
    $response->assertOk()->assertJsonPath('data.status', 'pending');
});

it('counts only the maker\'s own pending products', function () {
    // Given a maker with a draft, an approved, a rejected and a changes_requested product, and other makers at the cap
    $maker = signedInMember();
    pendingCap(1);
    Product::factory()->approved()->create(['maker_id' => $maker->id]);
    Product::factory()->rejected()->create(['maker_id' => $maker->id]);
    Product::factory()->changesRequested()->create(['maker_id' => $maker->id]);
    Product::factory()->complete()->create(['maker_id' => $maker->id]);
    Product::factory()->pending()->count(3)->create();
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // When they submit
    $response = $this->postJson("/api/v1/products/{$draft->id}/submit");

    // Then it is accepted
    $response->assertOk();
});

it('frees the slot when a pending product is withdrawn', function () {
    // Given a maker at the cap of one
    $maker = signedInMember();
    pendingCap(1);
    $pending = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $this->postJson("/api/v1/products/{$draft->id}/submit")->assertStatus(409)->assertJsonPath('code', 'pending_limit');

    // When they withdraw the pending one
    $this->postJson("/api/v1/products/{$pending->id}/withdraw")->assertOk();

    // Then the draft can be submitted
    $this->postJson("/api/v1/products/{$draft->id}/submit")->assertOk();
});

it('frees the slot when a reviewer decides on a pending product', function (string $decision) {
    // Given a maker at the cap of one
    $maker = User::factory()->create();
    pendingCap(1);
    $pending = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $submit = fn () => app(SubmitProduct::class)->handle($maker, $draft->fresh());
    expect($submit)->toThrow(ProductRuleViolation::class);

    // When a reviewer decides on the pending one
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $this->postJson("/api/v1/staff/products/{$pending->id}/review", [
        'submitted_at' => $pending->fresh()->submitted_at->toIso8601ZuluString('microsecond'),
        'decision' => $decision,
        'note' => 'Because',
    ])->assertOk();

    // Then the draft can be submitted
    expect($submit()->status)->toBe(ProductStatus::Pending);
})->with(['approved', 'rejected', 'changes_requested']);

it('reads the cap from the product.max_pending_per_maker setting', function () {
    // Given the cap lowered to one and a maker with one pending product
    $maker = signedInMember();
    Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $this->postJson("/api/v1/products/{$draft->id}/submit")->assertOk();
    $second = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    pendingCap(3);
    $this->postJson("/api/v1/products/{$second->id}/submit")->assertOk();
    $third = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $this->postJson("/api/v1/products/{$third->id}/submit")->assertStatus(409);

    // When the cap is lowered to one
    pendingCap(1);
    $fourth = Product::factory()->complete()->create(['maker_id' => $maker->id]);

    // Then even the next one is refused, with the same code
    $this->postJson("/api/v1/products/{$fourth->id}/submit")->assertStatus(409)->assertJsonPath('code', 'pending_limit');
});

it('counts a resubmission after changes_requested normally, the product itself is not pending then', function () {
    // Given a cap of two, one other pending product and a changes_requested product
    $maker = signedInMember();
    pendingCap(2);
    Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $mine = Product::factory()->changesRequested()->create(['maker_id' => $maker->id]);

    // When they resubmit it
    $first = $this->postJson("/api/v1/products/{$mine->id}/submit");

    // Then it is accepted, and a further draft is now over the cap
    $first->assertOk();
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $this->postJson("/api/v1/products/{$draft->id}/submit")->assertStatus(409)->assertJsonPath('code', 'pending_limit');
});

it('answers invalid_status and missing fields before the pending cap', function () {
    // Given a maker at the cap with an already pending product and an incomplete draft
    $maker = signedInMember();
    pendingCap(1);
    $pending = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $incomplete = Product::factory()->create(['maker_id' => $maker->id, 'tagline' => null]);

    // When they submit the pending one and the incomplete one
    $again = $this->postJson("/api/v1/products/{$pending->id}/submit");
    $gaps = $this->postJson("/api/v1/products/{$incomplete->id}/submit");

    // Then the first is invalid_status and the second a 422
    $again->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    $gaps->assertUnprocessable()->assertJsonValidationErrors(['tagline']);
});

it('locks the maker\'s whole product set in id order before it counts pending products', function () {
    // Given a maker with a complete draft and a recorder of queries
    $maker = User::factory()->create();
    $product = Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When submitted
    app(SubmitProduct::class)->handle($maker, $product);

    // Then the first locking query is the maker's set in id order and precedes the pending count
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'for update'));
    $count = collect($queries)->search(fn ($sql) => str_contains($sql, 'count(') && str_contains($sql, '"status"'));
    expect($queries[$lock])->toContain('"maker_id"')->toContain('order by "id" asc');
    expect($count)->not->toBeFalse()->and($lock)->toBeLessThan($count);
});

it('does not apply the cap to staff review', function () {
    // Given a maker with more pending products than the cap
    pendingCap(1);
    $maker = User::factory()->create();
    $products = Product::factory()->pending()->count(3)->create(['maker_id' => $maker->id]);
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When a reviewer approves one
    $response = $this->postJson("/api/v1/staff/products/{$products[0]->id}/review", [
        'submitted_at' => $products[0]->submitted_at->toIso8601ZuluString('microsecond'),
        'decision' => 'approved',
    ]);

    // Then it works
    $response->assertOk();
});
