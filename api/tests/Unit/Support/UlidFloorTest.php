<?php

use App\Support\UlidFloor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('is the smallest ULID of the millisecond, 26 valid Crockford characters long', function (string $instant) {
    // Given an instant
    $time = Carbon::parse($instant, 'UTC');

    // When the floor and some real ULIDs are made
    $floor = UlidFloor::at($time);

    // Then it is valid, not above a ULID of that millisecond and above a ULID two milliseconds earlier
    expect(strlen($floor))->toBe(26)->and(preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $floor))->toBe(1)->and(Str::isUlid($floor))->toBeTrue();
    expect($floor <= (string) Str::ulid($time))->toBeTrue();
    expect($floor > (string) Str::ulid($time->copy()->subMilliseconds(2)))->toBeTrue();
})->with(['2026-10-21 10:00:00.123', '2026-01-01 00:00:00', '2030-12-31 23:59:59.999', '2026-10-21 10:00:00.001']);

it('orders by time, so an id range selects rows created since the instant', function () {
    // Given a floor and ULIDs before and after it
    $floor = UlidFloor::at(Carbon::parse('2026-10-21 10:00:00', 'UTC'));

    // When they are compared
    // Then the earlier ULID is below and the later above
    expect((string) Str::ulid(Carbon::parse('2026-10-21 09:59:59', 'UTC')) < $floor)->toBeTrue();
    expect((string) Str::ulid(Carbon::parse('2026-10-21 10:00:01', 'UTC')) > $floor)->toBeTrue();
});
