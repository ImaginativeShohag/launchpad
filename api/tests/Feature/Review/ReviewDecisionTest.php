<?php

use App\Actions\Review\DecideReview;
use App\Enums\ProductStatus;
use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Events\ProductApproved;
use App\Events\ProductChangesRequested;
use App\Events\ProductRejected;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/** The token the reviewer saw: the product's submitted_at at full (microsecond) precision. */
function seenToken(Product $product): string
{
    $at = $product->fresh()->submitted_at;

    return $at === null ? '2026-01-01T00:00:00.000000Z' : $at->toIso8601ZuluString('microsecond');
}

function decide(Product $product, array $payload)
{
    return test()->postJson("/api/v1/staff/products/{$product->id}/review", array_merge(['submitted_at' => seenToken($product)], $payload));
}

it('approves a pending product, records the review and audits it without the note', function () {
    // Given a pending product and a reviewer
    Event::fake([ProductApproved::class]);
    $reviewer = signedInStaff();
    $product = Product::factory()->pending()->create();

    // When the reviewer approves it with an optional note
    $response = decide($product, ['decision' => 'approved', 'note' => 'Looks great']);

    // Then the status, approval time, review row, audit row and event all agree
    $response->assertOk()->assertJsonPath('data.status', 'approved');
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::Approved)->and($fresh->approved_at)->not->toBeNull();
    $review = ProductReview::query()->where('product_id', $product->id)->firstOrFail();
    expect($review->reviewer_id)->toBe($reviewer->id)->and($review->decision)->toBe(ReviewDecision::Approved)->and($review->note)->toBe('Looks great');
    $log = AuditLog::query()->where('action', 'product.reviewed')->firstOrFail();
    expect($log->actor_id)->toBe($reviewer->id)->and($log->subject_id)->toBe($product->id);
    expect($log->meta)->toEqual(['decision' => 'approved', 'review_id' => $review->id]);
    expect(json_encode($log->meta))->not->toContain('Looks great');
    Event::assertDispatched(ProductApproved::class, fn (ProductApproved $e) => $e->productId === $product->id && $e->reviewId === $review->id && $e->actorId === $reviewer->id);
});

it('rejects and requests changes with a required note, firing the matching event', function (string $decision, ProductStatus $status, string $event) {
    // Given a pending product and an admin reviewer
    Event::fake([ProductRejected::class, ProductChangesRequested::class]);
    signedInStaff(Role::Admin);
    $product = Product::factory()->pending()->create();

    // When they decide with a padded note containing a hidden character
    $response = decide($product, ['decision' => $decision, 'note' => "  Please add\u{202E} a proper logo.  "]);

    // Then the status moves, the note is stored cleaned, and the founder will see it
    $response->assertOk()->assertJsonPath('data.status', $status->value);
    expect($product->fresh()->status)->toBe($status);
    expect(ProductReview::query()->where('product_id', $product->id)->value('note'))->toBe('Please add a proper logo.');
    Event::assertDispatched($event);
})->with([
    'reject' => ['rejected', ProductStatus::Rejected, ProductRejected::class],
    'request changes' => ['changes_requested', ProductStatus::ChangesRequested, ProductChangesRequested::class],
]);

it('requires a note for rejections and change requests', function (string $decision, mixed $note) {
    // Given a pending product
    signedInStaff();
    $product = Product::factory()->pending()->create();

    // When the note is missing, empty or only whitespace and hidden characters
    $response = decide($product, ['decision' => $decision, 'note' => $note]);

    // Then it is a 422 on note and nothing is recorded
    $response->assertUnprocessable()->assertJsonValidationErrors('note');
    expect(ProductReview::query()->count())->toBe(0)->and($product->fresh()->status)->toBe(ProductStatus::Pending);
})->with([
    ['rejected', null], ['rejected', ''], ['rejected', "  \u{200B} "], ['changes_requested', null], ['changes_requested', "\n\n"],
]);

it('answers wrong-shaped decision and note with 422', function (string $field, mixed $value) {
    // Given a pending product
    signedInStaff();
    $product = Product::factory()->pending()->create();

    // When a field has the wrong shape
    $payload = array_merge(['decision' => 'approved', 'note' => 'ok', 'submitted_at' => seenToken($product)], [$field => $value]);
    $response = test()->postJson("/api/v1/staff/products/{$product->id}/review", $payload);

    // Then it is a 422 on that field, never a 500, and nothing is recorded
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(ProductReview::query()->count())->toBe(0);
})->with(function () {
    $cases = [];
    foreach (['decision', 'note', 'submitted_at'] as $field) {
        foreach (['array' => ['x'], 'empty array' => [], 'object' => ['a' => 'b'], 'integer' => 123, 'true' => true, 'false' => false] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }
    $cases['decision null'] = ['decision', null];
    $cases['decision empty'] = ['decision', ''];
    $cases['decision unknown'] = ['decision', 'wizard'];
    $cases['submitted_at null'] = ['submitted_at', null];
    $cases['submitted_at empty'] = ['submitted_at', ''];
    $cases['submitted_at garbage'] = ['submitted_at', 'yesterday'];
    $cases['submitted_at seconds only'] = ['submitted_at', '2026-10-18T10:00:00Z'];
    $cases['note too long'] = ['note', str_repeat('a', 2001)];

    return $cases;
});

it('refuses any product that is not pending with 409 and records nothing', function (string $state) {
    // Given a product in another status
    signedInStaff();
    $product = Product::factory()->{$state}()->create();

    // When a reviewer decides on it
    $response = decide($product, ['decision' => 'approved']);

    // Then it is a 409 and nothing changes
    $response->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    expect(ProductReview::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with(['approved', 'scheduled', 'live', 'rejected', 'changesRequested', 'takenDown']);

it('lets the first of two reviewers win and gives the second a 409', function () {
    // Given a pending product and two reviewers
    $product = Product::factory()->pending()->create();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $first = decide($product, ['decision' => 'approved']);
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When the second one decides on the same product
    $second = decide($product, ['decision' => 'rejected', 'note' => 'No']);

    // Then only the first decision exists
    $first->assertOk();
    $second->assertStatus(409);
    expect(ProductReview::query()->where('product_id', $product->id)->count())->toBe(1);
    expect($product->fresh()->status)->toBe(ProductStatus::Approved);
});

it('re-checks the status on the locked row, not on the stale model', function () {
    // Given a stale copy of a product that has meanwhile been approved
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $product = Product::factory()->pending()->create();
    $stale = Product::query()->findOrFail($product->id);
    app(DecideReview::class)->handle($reviewer, $product, ReviewDecision::Approved, null, seenToken($product));

    // When a second decision runs with the stale copy
    $again = fn () => app(DecideReview::class)->handle($reviewer, $stale, ReviewDecision::Rejected, 'late', seenToken($product));

    // Then the locked row says approved and the action refuses
    expect($again)->toThrow(ProductRuleViolation::class);
    expect(ProductReview::query()->count())->toBe(1);
});

it('locks the product row before it writes anything', function () {
    // Given a pending product and a recorder of locking queries
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $product = Product::factory()->pending()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When the decision runs
    app(DecideReview::class)->handle($reviewer, $product, ReviewDecision::Approved, null, seenToken($product));

    // Then the staff check (a plain read of the user) comes first, then the product's full-row lock, before any write
    $staff = collect($queries)->search(fn ($sql) => str_contains($sql, 'from "users"') && ! str_contains($sql, 'for update'));
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'select * from "products"') && str_contains($sql, 'for update'));
    $insert = collect($queries)->search(fn ($sql) => str_contains($sql, 'insert into "product_reviews"'));
    expect($staff)->not->toBeFalse()->and($lock)->not->toBeFalse()->and($insert)->not->toBeFalse();
    expect($staff)->toBeLessThan($lock)->and($lock)->toBeLessThan($insert);
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'for update') && str_contains($sql, '"users"')))->toBeEmpty();
});

it('refuses reviewers and admins deciding on their own product', function (Role $role) {
    // Given a staff member with a pending product of their own
    $staff = signedInStaff($role);
    $product = Product::factory()->pending()->create(['maker_id' => $staff->id]);

    // When they decide on it
    $response = decide($product, ['decision' => 'approved']);

    // Then it is refused with a stable code and nothing is recorded
    $response->assertForbidden()->assertJsonPath('code', 'own_product');
    expect(ProductReview::query()->count())->toBe(0)->and($product->fresh()->status)->toBe(ProductStatus::Pending);
})->with([[Role::Reviewer], [Role::Admin]]);

it('refuses a reviewer who lost the role after the request was authorised', function () {
    // Given a reviewer model that is stale: the database already says member
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $stale = User::query()->findOrFail($reviewer->id);
    DB::table('users')->where('id', $reviewer->id)->update(['role' => 'member']);
    $product = Product::factory()->pending()->create();

    // When the action runs with the stale model
    $call = fn () => app(DecideReview::class)->handle($stale, $product, ReviewDecision::Approved, null, seenToken($product));

    // Then it refuses and records nothing
    expect($call)->toThrow(AuthorizationException::class);
    expect(ProductReview::query()->count())->toBe(0);
});

it('refuses to decide on a product whose maker was deleted', function () {
    // Given a pending product whose maker row was anonymised behind DeleteAccount's back
    $reviewer = User::factory()->role(Role::Admin)->create();
    $product = Product::factory()->pending()->create();
    DB::table('users')->where('id', $product->maker_id)->update(['deleted_at' => now()]);

    // When the action runs
    $call = fn () => app(DecideReview::class)->handle($reviewer, $product, ReviewDecision::Approved, null, seenToken($product));

    // Then it answers maker_unavailable and nothing is recorded
    expect($call)->toThrow(ProductRuleViolation::class);
    expect(ProductReview::query()->count())->toBe(0)->and($product->fresh()->status)->toBe(ProductStatus::Pending);
});

it('refuses members before it even looks the product up, so ids cannot be probed', function () {
    // Given a pending product
    $product = Product::factory()->pending()->create();

    // When a member decides on it, and on an unknown id, with an invalid body
    signedInMember();
    $existing = decide($product, ['decision' => ['x']]);
    $unknown = test()->postJson('/api/v1/staff/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/review', ['decision' => ['x']]);

    // Then both are the same 403 (not 422, not 404)
    $existing->assertForbidden();
    $unknown->assertForbidden();
});

it('answers staff with 404 for an unknown product and for a draft', function () {
    // Given staff
    signedInStaff();
    $draft = Product::factory()->complete()->create();

    // When they decide on an unknown id and on a draft
    // Then both are the same 404: drafts are the founder's private work
    test()->postJson('/api/v1/staff/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/review', ['decision' => 'approved', 'submitted_at' => '2026-01-01T00:00:00.000000Z'])->assertNotFound();
    decide($draft, ['decision' => 'approved'])->assertNotFound();
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a pending product
    $product = Product::factory()->pending()->create();

    // When nobody, an unconfirmed reviewer and a suspended reviewer decide
    decide($product, ['decision' => 'approved'])->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->unverified()->create());
    decide($product, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->suspended()->create());
    decide($product, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('is a stale submission when the founder withdrew, edited and resubmitted after the reviewer opened it', function () {
    // Given a pending product that a reviewer opened (they hold its submitted_at token)
    $product = Product::factory()->pending()->create();
    $oldToken = seenToken($product);
    $maker = User::query()->findOrFail($product->maker_id);

    // And the founder withdraws it, swaps the content and submits it again
    Sanctum::actingAs($maker);
    $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertOk();
    $this->patchJson("/api/v1/products/{$product->id}", ['tagline' => 'Something completely different'])->assertOk();
    $this->postJson("/api/v1/products/{$product->id}/submit")->assertOk();
    $newToken = seenToken($product);

    // When the reviewer decides with the old token
    signedInStaff();
    $stale = test()->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'approved', 'submitted_at' => $oldToken]);

    // Then it is a 409 stale_submission, nothing is recorded and the product is still pending
    $stale->assertStatus(409)->assertJsonPath('code', 'stale_submission');
    expect($newToken)->not->toBe($oldToken);
    expect(ProductReview::query()->count())->toBe(0)->and(AuditLog::query()->where('action', 'product.reviewed')->count())->toBe(0);
    expect($product->fresh()->status)->toBe(ProductStatus::Pending)->and($product->fresh()->approved_at)->toBeNull();

    // And a fresh token (what the staff detail shows now) succeeds
    $detail = test()->getJson("/api/v1/staff/products/{$product->id}")->assertOk();
    $fresh = test()->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'approved', 'submitted_at' => $detail->json('data.submitted_at')]);
    $fresh->assertOk()->assertJsonPath('data.status', 'approved');
    expect(ProductReview::query()->count())->toBe(1);
});

it('compares the token to the microsecond', function () {
    // Given a pending product whose submitted_at differs from the token by one microsecond
    signedInStaff();
    $product = Product::factory()->pending()->create(['submitted_at' => '2026-10-18 10:00:00.123456+00']);
    $almost = '2026-10-18T10:00:00.123457Z';

    // When the reviewer sends the near-miss token, then the exact one
    $near = test()->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'approved', 'submitted_at' => $almost]);
    $exact = test()->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'approved', 'submitted_at' => '2026-10-18T10:00:00.123456Z']);

    // Then only the exact one is accepted
    $near->assertStatus(409)->assertJsonPath('code', 'stale_submission');
    $exact->assertOk();
});

it('shows the same token in the queue and the detail, at full precision', function () {
    // Given a pending product with a microsecond timestamp
    signedInStaff();
    $product = Product::factory()->pending()->create(['submitted_at' => '2026-10-18 10:00:00.123456+00']);

    // When staff read the queue and the detail
    $queue = test()->getJson('/api/v1/staff/review-queue')->assertOk();
    $detail = test()->getJson("/api/v1/staff/products/{$product->id}")->assertOk();

    // Then both return the exact ISO string the decision endpoint compares against
    expect($queue->json('data.0.submitted_at'))->toBe('2026-10-18T10:00:00.123456Z');
    expect($detail->json('data.submitted_at'))->toBe('2026-10-18T10:00:00.123456Z');
});

it('fires the decision event once after commit, and not on a refused or stale decision', function () {
    // Given a real listener (no fake), a pending product and the reviewer's token
    $fired = 0;
    Event::listen(ProductApproved::class, function () use (&$fired) {
        $fired++;
    });
    signedInStaff();
    $product = Product::factory()->pending()->create();

    // When a stale decision is refused first, then a fresh one is accepted
    decide($product, ['decision' => 'approved', 'submitted_at' => '2026-01-01T00:00:00.000000Z'])->assertStatus(409);
    $afterStale = $fired;
    decide($product, ['decision' => 'approved'])->assertSuccessful();

    // Then the event fired only for the accepted one
    expect($afterStale)->toBe(0)->and($fired)->toBe(1);
});
