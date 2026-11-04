<?php

use App\Actions\Product\TakeDownProduct;
use App\Actions\Product\UpdateProduct;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Events\ProductRestored;
use App\Events\ProductTakenDown;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

function takeDown(Product $product, array $payload)
{
    return test()->postJson("/api/v1/staff/products/{$product->id}/take-down", $payload);
}

it('lets an admin take a live product down with a reason, audited, and hides it from the public', function () {
    // Given a live product and an admin
    Event::fake([ProductTakenDown::class]);
    $admin = signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();
    expect(Product::visible()->whereKey($product->id)->exists())->toBeTrue();

    // When the admin takes it down
    $response = takeDown($product, ['reason' => '  Misleading screenshots  ']);

    // Then the status, time, admin and reason are stored, the product is hidden, and the audit and event agree
    $response->assertOk()->assertJsonPath('data.status', 'taken_down')->assertJsonPath('data.takedown_reason', 'Misleading screenshots');
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::TakenDown)->and($fresh->taken_down_at)->not->toBeNull()
        ->and($fresh->taken_down_by)->toBe($admin->id)->and($fresh->takedown_reason)->toBe('Misleading screenshots');
    expect(Product::visible()->whereKey($product->id)->exists())->toBeFalse();
    $log = AuditLog::query()->where('action', 'product.taken_down')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($product->id);
    expect($log->meta)->toEqual(['product_id' => $product->id, 'reason' => 'Misleading screenshots']);
    Event::assertDispatched(ProductTakenDown::class, fn (ProductTakenDown $e) => $e->productId === $product->id && $e->actorId === $admin->id);
});

it('restores a taken-down product to live, clears the take-down fields and audits it', function () {
    // Given a taken-down product and an admin
    Event::fake([ProductRestored::class]);
    $admin = signedInStaff(Role::Admin);
    $product = Product::factory()->takenDown()->create(['taken_down_by' => $admin->id]);

    // When the admin restores it
    $response = test()->postJson("/api/v1/staff/products/{$product->id}/restore");

    // Then it is live and public again with no take-down data, and the audit and event agree
    $response->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('data.takedown_reason', null);
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::Live)->and($fresh->taken_down_at)->toBeNull()
        ->and($fresh->taken_down_by)->toBeNull()->and($fresh->takedown_reason)->toBeNull();
    expect(Product::visible()->whereKey($product->id)->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'product.restored')->firstOrFail()->meta)->toEqual(['product_id' => $product->id]);
    Event::assertDispatched(ProductRestored::class, fn (ProductRestored $e) => $e->productId === $product->id);
});

it('keeps the history of earlier reasons in the audit log across take-down, restore and a second take-down', function () {
    // Given an admin and a live product
    signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When it is taken down, restored and taken down again with another reason
    takeDown($product, ['reason' => 'First reason'])->assertOk();
    test()->postJson("/api/v1/staff/products/{$product->id}/restore")->assertOk();
    takeDown($product, ['reason' => 'Second reason'])->assertOk();

    // Then the product carries the latest reason and the audit log both
    expect($product->fresh()->takedown_reason)->toBe('Second reason');
    expect(AuditLog::query()->where('action', 'product.taken_down')->get()->map(fn ($l) => $l->meta['reason'])->sort()->values()->all())->toBe(['First reason', 'Second reason']);
});

it('requires a plain-text reason', function (mixed $reason) {
    // Given an admin and a live product
    signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When the reason is missing, blank, too long or the wrong shape
    $response = takeDown($product, ['reason' => $reason]);

    // Then it is a 422 on reason and nothing changes
    $response->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($product->fresh()->status)->toBe(ProductStatus::Live)->and(AuditLog::query()->count())->toBe(0);
})->with([
    'null' => [null], 'empty' => [''], 'whitespace and hidden characters' => ["  \u{200B}\u{202E} "], 'too long' => [str_repeat('a', 501)],
    'array' => [['x']], 'empty array' => [[]], 'object' => [['a' => 'b']], 'integer' => [123], 'true' => [true], 'false' => [false],
]);

it('needs a reason field even when the body is empty', function () {
    // Given an admin and a live product
    signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When the body is empty
    // Then reason is required
    takeDown($product, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('only takes down live products and only restores taken-down ones, with 409 otherwise', function (string $state) {
    // Given an admin and a product in a status that is neither live (for take-down) nor taken down (for restore)
    signedInStaff(Role::Admin);
    $product = Product::factory()->{$state}()->create();

    // When both actions are tried
    $down = takeDown($product, ['reason' => 'Because']);
    $up = test()->postJson("/api/v1/staff/products/{$product->id}/restore");

    // Then both are 409 and nothing is recorded
    $down->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    $up->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    expect(AuditLog::query()->count())->toBe(0);
})->with(['pending', 'approved', 'scheduled', 'rejected', 'changesRequested']);

it('refuses a second take-down and a restore of a live product', function () {
    // Given an admin, a taken-down product and a live one
    signedInStaff(Role::Admin);
    $down = Product::factory()->takenDown()->create();
    $live = Product::factory()->live()->create();

    // When a take-down repeats, and a live product is restored
    // Then both are 409
    takeDown($down, ['reason' => 'Again'])->assertStatus(409);
    test()->postJson("/api/v1/staff/products/{$live->id}/restore")->assertStatus(409);
});

it('is admin only, checked before the product is looked up', function (Role $role) {
    // Given a live product
    $product = Product::factory()->live()->create();

    // When a member or reviewer tries, with an invalid body, on an existing and an unknown id
    signedInStaff($role);
    $existing = takeDown($product, ['reason' => ['x']]);
    $unknown = test()->postJson('/api/v1/staff/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/take-down', ['reason' => ['x']]);
    $restore = test()->postJson("/api/v1/staff/products/{$product->id}/restore");

    // Then all are the same 403 and nothing changes
    foreach ([$existing, $unknown, $restore] as $response) {
        $response->assertForbidden();
    }
    expect($product->fresh()->status)->toBe(ProductStatus::Live);
})->with([[Role::Member], [Role::Reviewer]]);

it('answers an admin asking for an unknown product with 404', function () {
    // Given an admin
    signedInStaff(Role::Admin);

    // When an unknown or soft-deleted product is taken down or restored
    $deleted = Product::factory()->live()->create(['deleted_at' => now()]);
    takeDown($deleted, ['reason' => 'x'])->assertNotFound();
    test()->postJson('/api/v1/staff/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/restore')->assertNotFound();
});

it('re-reads the admin role instead of trusting a stale model', function () {
    // Given an admin model that is stale: the database already says member
    $admin = User::factory()->role(Role::Admin)->create();
    $stale = User::query()->findOrFail($admin->id);
    DB::table('users')->where('id', $admin->id)->update(['role' => 'member']);
    $product = Product::factory()->live()->create();

    // When the action runs with the stale model
    $call = fn () => app(TakeDownProduct::class)->handle($stale, $product, 'Because');

    // Then it refuses and nothing changes
    expect($call)->toThrow(AuthorizationException::class);
    expect($product->fresh()->status)->toBe(ProductStatus::Live);
});

it('locks the product row before it writes', function () {
    // Given an admin, a live product and a recorder of queries
    $admin = User::factory()->role(Role::Admin)->create();
    $product = Product::factory()->live()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When the take-down runs
    app(TakeDownProduct::class)->handle($admin, $product, 'Because');

    // Then the first locking query is the product's, before the update
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'for update'));
    $update = collect($queries)->search(fn ($sql) => str_contains($sql, 'update "products"'));
    expect($queries[$lock])->toContain('"products"')->and($lock)->toBeLessThan($update);
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a live product
    $product = Product::factory()->live()->create();

    // When nobody, an unconfirmed admin and a suspended admin try
    takeDown($product, ['reason' => 'x'])->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    takeDown($product, ['reason' => 'x'])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    takeDown($product, ['reason' => 'x'])->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('answers a draft with 404 to an admin, like the review decision, and records nothing', function () {
    // Given an admin and a draft (never submitted or withdrawn): the founder's private work
    signedInStaff(Role::Admin);
    $draft = Product::factory()->complete()->create();

    // When a take-down and a restore are tried
    $down = takeDown($draft, ['reason' => 'Because']);
    $up = test()->postJson("/api/v1/staff/products/{$draft->id}/restore");

    // Then both answer the same 404 as an unknown id, not a 409 that confirms the id
    $down->assertNotFound();
    $up->assertNotFound();
    expect($draft->fresh()->status)->toBe(ProductStatus::Draft)->and(AuditLog::query()->count())->toBe(0);
});

it('refuses an edit from a stale live copy once the product is taken down', function () {
    // Given a maker's live product loaded before an admin takes it down
    $product = Product::factory()->live()->create();
    $maker = User::query()->findOrFail($product->maker_id);
    $stale = Product::query()->findOrFail($product->id);
    expect($stale->status)->toBe(ProductStatus::Live);
    $admin = User::factory()->role(Role::Admin)->create();
    app(TakeDownProduct::class)->handle($admin, $product, 'Because');

    // When the maker's stale copy is edited
    $call = fn () => app(UpdateProduct::class)->handle($maker, $stale, ['description' => 'Changed after take-down']);

    // Then the locked row says taken_down: refused as product_locked (403) and nothing is written
    expect($call)->toThrow(fn (ProductRuleViolation $e) => $e->errorCode === 'product_locked' && $e->status === 403);
    expect($product->fresh()->description)->not->toBe('Changed after take-down');
    expect(AuditLog::query()->where('action', 'product.edited_after_launch')->count())->toBe(0);
});

it('takes down a product that was edited meanwhile, and a stale taken-down copy does not block a take-down of the restored product', function () {
    // Given a live product, an admin, and a copy that still says taken_down although the database says live again
    $product = Product::factory()->live()->create();
    $maker = User::query()->findOrFail($product->maker_id);
    $admin = User::factory()->role(Role::Admin)->create();
    $stale = Product::query()->findOrFail($product->id);
    $stale->forceFill(['status' => ProductStatus::TakenDown]);

    // When the maker edits the live product and the admin then takes down through the stale copy
    app(UpdateProduct::class)->handle($maker, $product, ['description' => 'Edited while live']);
    app(TakeDownProduct::class)->handle($admin, $stale, 'Because');

    // Then the take-down saw the live row: it succeeded and the edit is kept
    expect($product->fresh()->status)->toBe(ProductStatus::TakenDown)->and($product->fresh()->description)->toBe('Edited while live');
});

it('fires the events once after commit and not for a refused request', function () {
    // Given real listeners counting the events
    $seen = [];
    Event::listen(ProductTakenDown::class, function (ProductTakenDown $e) use (&$seen) {
        $seen[] = 'down';
    });
    Event::listen(ProductRestored::class, function (ProductRestored $e) use (&$seen) {
        $seen[] = 'up';
    });
    signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When a take-down succeeds, then repeats (409), then a restore succeeds
    takeDown($product, ['reason' => 'Because'])->assertOk();
    takeDown($product, ['reason' => 'Because'])->assertStatus(409);
    test()->postJson("/api/v1/staff/products/{$product->id}/restore")->assertOk();
    test()->postJson("/api/v1/staff/products/{$product->id}/restore")->assertStatus(409);

    // Then each ran exactly once
    expect($seen)->toBe(['down', 'up']);
});

it('rate limits take-down and restore with admin-write', function () {
    // Given the routes
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/staff/products/') && (str_ends_with($r->uri(), '/take-down') || str_ends_with($r->uri(), '/restore')));

    // When / Then both carry the admin-write limiter
    expect($routes)->toHaveCount(2);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('throttle:admin-write');
    }
});

it('answers odd id segments from an admin with 404, never 500', function (string $id) {
    // Given an admin
    signedInStaff(Role::Admin);

    // When an id that is not a ULID reaches either route
    $down = test()->postJson('/api/v1/staff/products/'.$id.'/take-down', ['reason' => 'Because']);
    $up = test()->postJson('/api/v1/staff/products/'.$id.'/restore');

    // Then both are 404
    $down->assertNotFound();
    $up->assertNotFound();
})->with(['text' => 'not-an-id', 'long' => str_repeat('A', 200), 'encoded nul' => '%00', 'quote' => '%27%3B--']);

it('stores a reason with bidi and invisible characters stripped', function () {
    // Given an admin and a live product
    signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When the reason mixes text with a bidi override and a zero-width space
    takeDown($product, ['reason' => "Mis\u{202E}leading\u{200B} claims"])->assertOk();

    // Then the stored reason has them stripped
    expect($product->fresh()->takedown_reason)->toBe('Misleading claims');
});

it('requires sign-in, a confirmed email and an active account on restore too', function () {
    // Given a taken-down product
    $product = Product::factory()->takenDown()->create();

    // When nobody, an unconfirmed admin and a suspended admin restore it
    test()->postJson("/api/v1/staff/products/{$product->id}/restore")->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    test()->postJson("/api/v1/staff/products/{$product->id}/restore")->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    test()->postJson("/api/v1/staff/products/{$product->id}/restore")->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect($product->fresh()->status)->toBe(ProductStatus::TakenDown);
});

it('refuses to restore the product of a suspended or deleted maker', function (string $state) {
    // Given an admin and a taken-down product whose maker is not active
    signedInStaff(Role::Admin);
    $maker = User::factory()->state($state === 'deleted' ? ['deleted_at' => now()] : ['suspended_at' => now()])->create();
    $product = Product::factory()->takenDown()->create(['maker_id' => $maker->id]);

    // When the admin restores it
    $response = test()->postJson("/api/v1/staff/products/{$product->id}/restore");

    // Then it is a 409 maker_unavailable and the product stays taken down
    $response->assertStatus(409)->assertJsonPath('code', 'maker_unavailable');
    expect($product->fresh()->status)->toBe(ProductStatus::TakenDown)->and(AuditLog::query()->where('action', 'product.restored')->count())->toBe(0);
})->with(['suspended', 'deleted']);
