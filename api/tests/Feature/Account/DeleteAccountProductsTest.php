<?php

use App\Actions\Auth\DeleteAccount;
use App\Enums\AuthEventType;
use App\Enums\Role;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuthEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

function memberDeleting(): User
{
    return tap(User::factory()->create(), fn (User $user) => Sanctum::actingAs($user));
}

function deleteMe()
{
    return test()->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery']);
}

it('blocks account deletion while a product is pending, approved, scheduled or live', function (string $state) {
    // Given a member with such a product and a draft
    $user = memberDeleting();
    Product::factory()->{$state}()->create(['maker_id' => $user->id]);
    $draft = Product::factory()->complete()->create(['maker_id' => $user->id]);

    // When they delete their account
    $response = deleteMe();

    // Then it is refused with a stable code and nothing at all changes
    $response->assertForbidden()->assertJsonPath('code', 'has_active_product');
    expect($user->fresh()->deleted_at)->toBeNull()->and($user->fresh()->name)->toBe($user->name);
    expect(Product::query()->whereKey($draft->id)->exists())->toBeTrue();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::AccountDeleted)->exists())->toBeFalse();
})->with(['pending', 'approved', 'scheduled', 'live']);

it('lets a member with only rejected or taken-down products delete the account and keeps those records hidden', function () {
    // Given a member with a rejected and a taken-down product
    $user = memberDeleting();
    $rejected = Product::factory()->rejected()->create(['maker_id' => $user->id]);
    $down = Product::factory()->takenDown()->create(['maker_id' => $user->id]);

    // When they delete their account
    deleteMe()->assertNoContent();

    // Then both products stay as hidden records and the maker row is the anonymised one
    expect(Product::query()->whereKey([$rejected->id, $down->id])->count())->toBe(2);
    expect(Product::visible()->count())->toBe(0);
    expect($user->fresh()->deleted_at)->not->toBeNull();
    $this->getJson("/api/v1/products/{$down->slug}")->assertNotFound();
});

it('soft-deletes drafts and products with requested changes, frees their slugs and removes their files after the commit', function () {
    // Given a draft and a changes_requested product with a logo and a screenshot on disk each
    $user = memberDeleting();
    $draft = Product::factory()->complete()->create(['maker_id' => $user->id, 'slug' => 'my-draft']);
    $changes = Product::factory()->changesRequested()->create(['maker_id' => $user->id]);
    $files = [];
    foreach ([$draft, $changes] as $product) {
        $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/a/b', 'position' => 0]);
        $files = array_merge($files, [$product->logo_path], $product->screenshots()->pluck('path')->all());
    }
    foreach ($files as $file) {
        Storage::disk('public')->put($file, 'x');
    }

    // When they delete their account
    deleteMe()->assertNoContent();

    // Then both are soft-deleted under freed slugs, their child rows and files are gone
    foreach ([$draft, $changes] as $product) {
        $deleted = Product::withTrashed()->findOrFail($product->id);
        expect($deleted->deleted_at)->not->toBeNull()->and($deleted->slug)->toBe('deleted-'.strtolower($product->id))->and($deleted->logo_path)->toBeNull();
        expect(DB::table('product_screenshots')->where('product_id', $product->id)->count())->toBe(0);
        expect(DB::table('product_topics')->where('product_id', $product->id)->count())->toBe(0);
        expect(DB::table('product_links')->where('product_id', $product->id)->count())->toBe(0);
    }
    foreach ($files as $file) {
        Storage::disk('public')->assertMissing($file);
    }

    // And another member can now use the old slug
    Sanctum::actingAs(User::factory()->create());
    test()->postJson('/api/v1/products', ['name' => 'Taken Over', 'slug' => 'my-draft'])->assertCreated();
});

it('still answers 204 and reports it when a product file cannot be removed', function () {
    // Given a member with a draft and a disk that fails on delete
    Exceptions::fake();
    $user = memberDeleting();
    Product::factory()->complete()->create(['maker_id' => $user->id]);
    Storage::shouldReceive('disk')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk gone'));

    // When they delete their account
    $response = deleteMe();

    // Then the account is deleted and the failure was reported
    $response->assertNoContent();
    expect($user->fresh()->deleted_at)->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('re-checks for active products inside the action, on locked rows, and rolls everything back', function () {
    // Given a member with a draft and a live product, with the policy check skipped (the action is called directly)
    $user = User::factory()->create();
    $draft = Product::factory()->complete()->create(['maker_id' => $user->id]);
    Product::factory()->live()->create(['maker_id' => $user->id]);

    // When the action runs
    $call = fn () => app(DeleteAccount::class)->handle($user);

    // Then it refuses, the account is untouched and the draft was not deleted
    expect($call)->toThrow(ProductRuleViolation::class);
    expect($user->fresh()->deleted_at)->toBeNull()->and(Product::query()->whereKey($draft->id)->exists())->toBeTrue();
});

it('locks the member\'s products first, then the admin set, then the user row', function () {
    // Given two admins, one with a draft, and a recorder of locking queries
    $admin = User::factory()->role(Role::Admin)->create();
    User::factory()->role(Role::Admin)->create();
    Product::factory()->complete()->create(['maker_id' => $admin->id]);
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    // When one admin is deleted
    app(DeleteAccount::class)->handle($admin);

    // Then the first lock is the member's products, the second the role-filtered admin set, the third the user row
    expect($locks[0])->toContain('"products"')->toContain('"maker_id"')->toContain('order by "id" asc');
    expect($locks[1])->toContain('"users"')->toContain('"role"');
    expect($locks[2])->toContain('"users"')->not->toContain('"role"');
});

it('locks the products before the user row for an ordinary member too', function () {
    // Given a member and a recorder
    $user = User::factory()->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    // When the member is deleted
    app(DeleteAccount::class)->handle($user);

    // Then products come first and the user row second
    expect($locks[0])->toContain('"products"')->and($locks[1])->toContain('"users"');
});

it('is idempotent for a member who was already deleted', function () {
    // Given a stale copy of a member who has meanwhile been deleted (with a draft that was removed with them)
    $user = User::factory()->create();
    Product::factory()->complete()->create(['maker_id' => $user->id]);
    $stale = User::query()->findOrFail($user->id);
    app(DeleteAccount::class)->handle(User::query()->findOrFail($user->id));

    // When the stale copy is deleted again
    $second = app(DeleteAccount::class)->handle($stale);

    // Then nothing happens twice
    expect($second)->toBeFalse();
    expect(Product::withTrashed()->where('maker_id', $user->id)->whereNull('deleted_at')->count())->toBe(0);
});

it('refuses with a retryable 409 and changes nothing when a draft appears while the locks are being taken', function () {
    // Given a member with a draft, and a new draft that is inserted right after the user row is locked
    $user = User::factory()->create();
    Product::factory()->complete()->create(['maker_id' => $user->id]);
    $inserted = false;
    DB::listen(function ($query) use ($user, &$inserted) {
        if (! $inserted && str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'for update') && ! str_contains($query->sql, '"role"')) {
            $inserted = true;
            Product::factory()->complete()->create(['maker_id' => $user->id]);
        }
    });

    // When the account is deleted
    $call = fn () => app(DeleteAccount::class)->handle($user);

    // Then it is refused as try_again, and neither the user nor any product was touched
    expect($call)->toThrow(ProductRuleViolation::class);
    expect($inserted)->toBeTrue()->and($user->fresh()->deleted_at)->toBeNull();
    // (the injected draft lived in the rolled-back transaction, so only the original remains, untouched)
    expect(Product::query()->where('maker_id', $user->id)->count())->toBe(1);
});

it('keeps the files and slug of a draft when the deletion is refused by the locked re-check', function () {
    // Given a member with a draft and a live product
    Storage::fake('public');
    $user = User::factory()->create();
    $draft = Product::factory()->complete()->create(['maker_id' => $user->id, 'slug' => 'keep-me']);
    Product::factory()->live()->create(['maker_id' => $user->id]);

    // When the action runs (the policy check was skipped, as in a race)
    $call = fn () => app(DeleteAccount::class)->handle($user);

    // Then it refuses and the draft is untouched
    expect($call)->toThrow(ProductRuleViolation::class);
    expect($draft->fresh()->slug)->toBe('keep-me')->and($draft->fresh()->deleted_at)->toBeNull();
});
