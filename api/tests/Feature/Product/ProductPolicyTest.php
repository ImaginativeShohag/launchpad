<?php

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Support\Facades\Gate;

it('lets only the maker manage a product and hides it from everyone else as a 404', function () {
    // Given a product and four other people
    $product = Product::factory()->create();
    $maker = User::query()->findOrFail($product->maker_id);
    $other = User::factory()->create();
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $admin = User::factory()->role(Role::Admin)->create();

    // When the manage ability is inspected
    // Then the maker is allowed and everyone else (staff included) is told 404
    expect(Gate::forUser($maker)->inspect('manage', $product)->allowed())->toBeTrue();
    foreach ([$other, $reviewer, $admin] as $person) {
        $decision = Gate::forUser($person)->inspect('manage', $product);
        expect($decision->allowed())->toBeFalse()->and($decision->status())->toBe(404);
    }
});

it('limits review to staff, take-down and restore to admins', function () {
    // Given one person per role
    $member = User::factory()->create();
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $admin = User::factory()->role(Role::Admin)->create();

    // When the class-level abilities are asked
    // Then review is for reviewer and admin, take-down and restore for admin only
    expect(Gate::forUser($member)->allows('review', Product::class))->toBeFalse();
    expect(Gate::forUser($reviewer)->allows('review', Product::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('review', Product::class))->toBeTrue();
    expect(Gate::forUser($reviewer)->allows('takeDown', Product::class))->toBeFalse();
    expect(Gate::forUser($admin)->allows('takeDown', Product::class))->toBeTrue();
    expect(Gate::forUser($reviewer)->allows('restore', Product::class))->toBeFalse();
    expect(Gate::forUser($admin)->allows('restore', Product::class))->toBeTrue();
});

it('shows staff every product except drafts', function (ProductStatus $status, bool $visible) {
    // Given a product in some status and a reviewer
    $product = Product::factory()->create(['status' => $status, 'launch_date' => in_array($status, [ProductStatus::Scheduled, ProductStatus::Live, ProductStatus::TakenDown], true) ? '2030-01-01' : null, 'submitted_at' => $status === ProductStatus::Pending ? now() : null]);
    $reviewer = User::factory()->role(Role::Reviewer)->create();

    // When the staff view ability is inspected
    $decision = Gate::forUser($reviewer)->inspect('viewStaff', $product);

    // Then only drafts are a 404; a member never gets the staff view
    expect($decision->allowed())->toBe($visible);
    if (! $visible) {
        expect($decision->status())->toBe(404);
    }
    expect(Gate::forUser(User::factory()->create())->allows('viewStaff', $product))->toBeFalse();
})->with(array_map(fn (ProductStatus $s) => [$s, $s !== ProductStatus::Draft], ProductStatus::cases()));

it('allows exactly the documented fields per status', function (ProductStatus $status, array $fields, bool $media) {
    // Given a status
    // When the matrix is read
    // Then it matches the spec's edit-rule table
    expect(ProductPolicy::editableFields($status))->toBe($fields);
    expect(ProductPolicy::mediaEditable($status))->toBe($media);
})->with(function () {
    $all = ['name', 'slug', 'tagline', 'description', 'website_url', 'links', 'topic_ids', 'screenshots'];

    return [
        'draft' => [ProductStatus::Draft, $all, true],
        'changes_requested' => [ProductStatus::ChangesRequested, $all, true],
        'pending' => [ProductStatus::Pending, [], false],
        'approved' => [ProductStatus::Approved, [], false],
        'scheduled' => [ProductStatus::Scheduled, [], false],
        'live' => [ProductStatus::Live, ['description', 'website_url', 'links'], false],
        'rejected' => [ProductStatus::Rejected, [], false],
        'taken_down' => [ProductStatus::TakenDown, [], false],
    ];
});

it('answers a member asking for the staff view with a 404, not a 403', function () {
    // Given a pending product and a plain member
    $product = Product::factory()->pending()->create();
    $member = User::factory()->create();

    // When the staff view is inspected for them
    $decision = Gate::forUser($member)->inspect('viewStaff', $product);

    // Then it is refused as not found, so existing ids cannot be told from missing ones
    expect($decision->allowed())->toBeFalse()->and($decision->status())->toBe(404);
});

it('does not overwrite a decision made by another request (stale instance)', function () {
    // Given two copies of the same pending product, as two reviewers would load them
    $product = Product::factory()->pending()->create();
    $copyA = Product::query()->findOrFail($product->id);
    $copyB = Product::query()->findOrFail($product->id);

    // When the first approves and commits, and the second then tries to reject from its stale copy
    $copyA->transitionTo(ProductStatus::Approved);
    $copyA->save();
    $second = fn () => $copyB->transitionTo(ProductStatus::Rejected);

    // Then the second is refused because the stored status is no longer pending
    expect($second)->toThrow(ProductRuleViolation::class);
    expect(Product::query()->findOrFail($product->id)->status)->toBe(ProductStatus::Approved);
});

it('allows every edge of the status graph and nothing else', function (ProductStatus $from, ProductStatus $to, bool $legal) {
    // Given a product in the starting status
    $product = Product::factory()->create();
    Product::query()->whereKey($product->id)->update(['status' => $from->value, 'launch_date' => in_array($from, [ProductStatus::Scheduled, ProductStatus::Live], true) ? '2026-10-01' : null, 'submitted_at' => $from === ProductStatus::Pending ? now() : null]);
    $product = Product::query()->findOrFail($product->id);

    // When it moves to the target
    // (callers set their own timestamps; a pending product needs its submission time)
    $attempt = function () use ($product, $to) {
        if ($to === ProductStatus::Pending) {
            $product->submitted_at = now();
        }

        return $product->transitionTo($to);
    };

    // Then only the edges of the spec's diagram are accepted
    $legal ? expect($attempt)->not->toThrow(ProductRuleViolation::class) : expect($attempt)->toThrow(ProductRuleViolation::class);
})->with(function () {
    $cases = [];
    foreach (ProductStatus::cases() as $from) {
        foreach (ProductStatus::cases() as $to) {
            $cases["{$from->value} -> {$to->value}"] = [$from, $to, $from->canTransitionTo($to)];
        }
    }

    return $cases;
});
