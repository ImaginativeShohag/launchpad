<?php

use App\Casts\MicrosecondTimestamp;
use App\Models\Product;
use Illuminate\Support\Carbon;

it('writes the moment with its microseconds and an explicit offset', function () {
    // Given a moment with a fractional second
    $cast = new MicrosecondTimestamp;
    $moment = Carbon::parse('2026-10-18 10:00:00.123456', 'UTC');

    // When it is prepared for storage
    // Then no digit is lost
    expect($cast->set(new Product, 'submitted_at', $moment, []))->toBe('2026-10-18 10:00:00.123456+00:00');
    expect($cast->set(new Product, 'submitted_at', '2026-10-18T12:00:00.5+02:00', []))->toBe('2026-10-18 10:00:00.500000+00:00');
    expect($cast->set(new Product, 'submitted_at', null, []))->toBeNull();
});

it('reads back the same moment, and prints it with microseconds so a keyset cursor does not round it down', function () {
    // Given a stored value
    $cast = new MicrosecondTimestamp;

    // When it is read
    $read = $cast->get(new Product, 'submitted_at', '2026-10-18 10:00:00.123456+00', []);

    // Then the instant is exact and (string) keeps the fraction (Laravel's cursor uses (string) on the value)
    expect($read->toIso8601ZuluString('microsecond'))->toBe('2026-10-18T10:00:00.123456Z');
    expect((string) $read)->toBe('2026-10-18 10:00:00.123456+00:00');
    expect($cast->get(new Product, 'submitted_at', null, []))->toBeNull();
});
