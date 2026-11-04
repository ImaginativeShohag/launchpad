<?php

use App\Rules\KeysetCursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

function keysetIntCursorOf(mixed $payload): string
{
    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
}

function keysetIntCursorPasses(mixed $value, array $shape = ['replies_count' => 'int', 'created_at' => 'date', 'id' => 'ulid']): bool
{
    return Validator::make(['cursor' => $value], ['cursor' => [new KeysetCursor($shape)]])->passes();
}

it('accepts a JSON integer in range for an int column', function (int $replies) {
    // Given a top-sort cursor with an integer replies_count
    $cursor = keysetIntCursorOf(['replies_count' => $replies, 'created_at' => '2026-10-18T10:00:00.000000Z', 'id' => (string) Str::ulid(), '_pointsToNextItems' => true]);

    // When / Then
    expect(keysetIntCursorPasses($cursor))->toBeTrue();
})->with([0, 3, 1000000]);

it('refuses anything but a JSON integer from 0 to 1000000 for an int column', function (mixed $replies) {
    // Given a cursor whose replies_count has the wrong type or range
    $cursor = keysetIntCursorOf(['replies_count' => $replies, 'created_at' => '2026-10-18T10:00:00.000000Z', 'id' => (string) Str::ulid(), '_pointsToNextItems' => true]);

    // When / Then
    expect(keysetIntCursorPasses($cursor))->toBeFalse();
})->with([
    'string' => ['3'],
    'float' => [3.5],
    'negative' => [-1],
    'too large' => [1000001],
    'true' => [true],
    'null' => [null],
    'array' => [[3]],
    'object' => [['a' => 3]],
]);

it('refuses a whole float written as 3.0 for an int column', function () {
    // Given a cursor whose replies_count was written as "3.0" (json_encode would drop the fraction, so write the JSON by hand)
    $json = '{"replies_count":3.0,"created_at":"2026-10-18T10:00:00.000000Z","id":"'.Str::ulid().'","_pointsToNextItems":true}';
    $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    // When / Then
    expect(keysetIntCursorPasses($cursor))->toBeFalse();
});

it('refuses the int column in the wrong key order or missing', function () {
    // Given the same values in the wrong order, and a cursor of the other sort
    $ulid = (string) Str::ulid();
    $date = '2026-10-18T10:00:00.000000Z';

    // When / Then
    expect(keysetIntCursorPasses(keysetIntCursorOf(['created_at' => $date, 'replies_count' => 3, 'id' => $ulid, '_pointsToNextItems' => true])))->toBeFalse();
    expect(keysetIntCursorPasses(keysetIntCursorOf(['created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true])))->toBeFalse();
    expect(keysetIntCursorPasses(keysetIntCursorOf(['replies_count' => 3, 'created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]), ['created_at' => 'date', 'id' => 'ulid']))->toBeFalse();
});
