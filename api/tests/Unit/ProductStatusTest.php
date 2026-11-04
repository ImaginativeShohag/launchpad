<?php

use App\Enums\LinkKind;
use App\Enums\ProductStatus;
use App\Enums\ReviewDecision;

it('allows exactly the transitions in the lifecycle diagram', function () {
    // Given the lifecycle from the spec (section 5)
    $allowed = [
        'draft' => ['pending'],
        'changes_requested' => ['pending'],
        'pending' => ['approved', 'rejected', 'changes_requested', 'draft'],
        'approved' => ['scheduled', 'draft'],
        'scheduled' => ['live', 'draft'],
        'live' => ['taken_down'],
        'taken_down' => ['live'],
        'rejected' => [],
    ];

    // When every ordered pair of statuses is asked
    foreach (ProductStatus::cases() as $from) {
        foreach (ProductStatus::cases() as $to) {
            // Then only the listed pairs are allowed
            expect($from->canTransitionTo($to))
                ->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});

it('lists the statuses that block account deletion', function () {
    // Given / When
    $blocking = array_map(fn (ProductStatus $s) => $s->value, ProductStatus::blockingDeletion());

    // Then
    expect($blocking)->toBe(['pending', 'approved', 'scheduled', 'live']);
});

it('maps every review decision to the status it produces', function () {
    // Given / When / Then
    expect(ReviewDecision::Approved->toStatus())->toBe(ProductStatus::Approved);
    expect(ReviewDecision::Rejected->toStatus())->toBe(ProductStatus::Rejected);
    expect(ReviewDecision::ChangesRequested->toStatus())->toBe(ProductStatus::ChangesRequested);
    expect(ReviewDecision::Approved->needsNote())->toBeFalse();
    expect(ReviewDecision::Rejected->needsNote())->toBeTrue();
    expect(ReviewDecision::ChangesRequested->needsNote())->toBeTrue();
});

it('offers every link kind except website for product links', function () {
    // Given / When
    $kinds = LinkKind::productKinds();

    // Then
    expect($kinds)->not->toContain('website')->toContain('github')->toHaveCount(count(LinkKind::cases()) - 1);
});
