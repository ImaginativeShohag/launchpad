<?php

use App\Events\ProductApproved;
use App\Listeners\NotifyReviewOutcome;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/MailFixtures.php';
require_once __DIR__.'/../../Support/NotificationFixtures.php';

afterEach(fn () => AuditLog::flushEventListeners());

it('notifies the maker of each review outcome once, in the bell and by mail, with no reviewer note', function (string $decision, string $type, ?string $note) {
    // Given a pending product of a verified maker and a reviewer (real listeners, the sync queue)
    $maker = User::factory()->create();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id, 'name' => 'Acme']);
    signedInStaff();

    // When the reviewer decides
    producerReview($product, $decision, $note)->assertOk();

    // Then exactly one bell row and one outbox row exist for the maker
    [$bell, $mail] = producerRows();
    $review = DB::table('product_reviews')->where('product_id', $product->id)->first();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(1);
    expect($bell[0]['user_id'])->toBe($maker->id)->and($bell[0]['type'])->toBe($type)->and($bell[0]['dedupe_key'])->toBe('review:'.$review->id)->and($bell[0]['read_at'])->toBeNull();
    expect(json_decode($bell[0]['data'], true))->toEqual([
        'message_key' => 'notifications.'.$type, 'product_id' => $product->id, 'review_id' => $review->id,
        'params' => ['product_name' => 'Acme'], 'href' => '/me/products/'.$product->id,
    ]);
    expect($mail[0]['user_id'])->toBe($maker->id)->and($mail[0]['type'])->toBe('review_outcome')->and($mail[0]['status'])->toBe('queued')
        ->and($mail[0]['idempotency_key'])->toBe('review_outcome:'.$review->id.':'.$maker->id)->and($mail[0]['to_email'])->toBe($maker->email);
    expect(json_decode($mail[0]['payload'], true))->toEqual(['review_id' => $review->id, 'product_id' => $product->id, 'decision' => $decision]);

    // And the reviewer's note is in neither row
    expect(json_encode([$bell, $mail]))->not->toContain('SECRET-NOTE');

    // And the maker's bell got exactly one live count frame, carrying a count and an id only
    $frames = notifFramesOn('private-user.'.$maker->id);
    expect($frames)->toHaveCount(1)->and($frames[0]['name'])->toBe('notification.count')->and($frames[0]['data'])->toBe(['unread_count' => 1, 'notification_id' => $bell[0]['id']]);
})->with([
    'approved' => ['approved', 'review_approved', null],
    'rejected' => ['rejected', 'review_rejected', 'SECRET-NOTE'],
    'changes requested' => ['changes_requested', 'review_changes_requested', 'SECRET-NOTE'],
]);

it('writes nothing for a refused review: stale token, non-staff and invalid input', function () {
    // Given a pending product
    $product = Product::factory()->pending()->create();

    // When a non-staff member, a reviewer with a stale token, and a reviewer with an invalid decision try
    signedInMember();
    $this->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'approved', 'submitted_at' => '2026-01-01T00:00:00.000000Z'])->assertForbidden();
    signedInStaff();
    $this->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'approved', 'submitted_at' => '2026-01-01T00:00:00.000000Z'])->assertStatus(409);
    $this->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'maybe', 'submitted_at' => '2026-01-01T00:00:00.000000Z'])->assertUnprocessable();
    $this->postJson("/api/v1/staff/products/{$product->id}/review", ['decision' => 'rejected', 'submitted_at' => $product->fresh()->submitted_at->toIso8601ZuluString('microsecond')])->assertUnprocessable();

    // Then no row was written anywhere
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('writes nothing when the review transaction rolls back', function () {
    // Given a pending product and an audit write that fails inside the review transaction
    $product = Product::factory()->pending()->create();
    signedInStaff();
    AuditLog::creating(function () {
        throw new RuntimeException('audit broke');
    });
    Exceptions::fake();

    // When the reviewer decides
    $response = producerReview($product, 'approved');

    // Then the request failed, the product is still pending and no notification exists (the event fires after commit only)
    expect($response->status())->toBe(500);
    expect($product->fresh()->status->value)->toBe('pending')->and(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('gives an unverified maker the bell and no mail', function () {
    // Given a product of an unverified maker
    $maker = User::factory()->unverified()->create();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    signedInStaff();

    // When the reviewer approves
    producerReview($product, 'approved')->assertOk();

    // Then only the bell row exists
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 0]);
});

it('honours a maker who switched review mail off', function () {
    // Given a maker who opted out of review mail
    $maker = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $maker->id, 'type' => 'review_outcome', 'enabled' => false, 'updated_at' => now()]);
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    signedInStaff();

    // When the reviewer approves
    producerReview($product, 'approved')->assertOk();

    // Then the bell has it and the outbox does not
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 0]);
});

it('leaves one row each when the same event is dispatched twice or the listener job re-runs', function () {
    // Given a recorded approval
    $maker = User::factory()->create();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    signedInStaff();
    producerReview($product, 'approved')->assertOk();
    $review = DB::table('product_reviews')->where('product_id', $product->id)->first();

    // When the event is dispatched again and the listener is called directly again
    ProductApproved::dispatch($product->id, null, $review->id);
    app(NotifyReviewOutcome::class)->notify(new ProductApproved($product->id, null, $review->id));

    // Then there is still one bell row and one outbox row
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1]);
});

it('writes nothing for a review whose product was soft-deleted or never existed', function () {
    // Given a review row whose product is soft-deleted, and an unknown review id
    $product = Product::factory()->pending()->create();
    signedInStaff();
    producerReview($product, 'approved')->assertOk();
    DB::table('notifications')->delete();
    DB::table('email_messages')->delete();
    $review = DB::table('product_reviews')->where('product_id', $product->id)->first();
    $product->refresh()->delete();

    // When the listener runs for both
    app(NotifyReviewOutcome::class)->notify(new ProductApproved($product->id, null, $review->id));
    app(NotifyReviewOutcome::class)->notify(new ProductApproved($product->id, null, strtolower((string) Str::ulid())));

    // Then nothing is written
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});
