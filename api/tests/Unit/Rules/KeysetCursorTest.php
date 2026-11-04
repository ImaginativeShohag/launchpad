<?php

use App\Rules\KeysetCursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

function keysetCursorOf(mixed $payload): string
{
    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
}

function keysetCursorPasses(mixed $value): bool
{
    return Validator::make(['cursor' => $value], ['cursor' => [new KeysetCursor(['submitted_at' => 'date', 'id' => 'ulid'])]])->passes();
}

it('accepts a cursor shaped exactly like the pagination one', function () {
    // Given a real-looking cursor
    $cursor = keysetCursorOf(['submitted_at' => '2026-10-18T10:00:00.000000Z', 'id' => (string) Str::ulid(), '_pointsToNextItems' => true]);

    // When / Then
    expect(keysetCursorPasses($cursor))->toBeTrue();
});

it('refuses tampered cursors', function (mixed $value) {
    // Given / When / Then
    expect(keysetCursorPasses($value))->toBeFalse();
})->with(function () {
    $ulid = (string) Str::ulid();
    $date = '2026-10-18T10:00:00.000000Z';

    return [
        'json null' => ['bnVsbA'],
        'empty list' => [keysetCursorOf([])],
        'not base64' => ['!!!not base64!!!'],
        'too long' => [str_repeat('a', 513)],
        'wrong keys' => [keysetCursorOf(['created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true])],
        'extra key' => [keysetCursorOf(['submitted_at' => $date, 'id' => $ulid, 'x' => 1, '_pointsToNextItems' => true])],
        'wrong order' => [keysetCursorOf(['id' => $ulid, 'submitted_at' => $date, '_pointsToNextItems' => true])],
        'missing flag' => [keysetCursorOf(['submitted_at' => $date, 'id' => $ulid])],
        'flag not bool' => [keysetCursorOf(['submitted_at' => $date, 'id' => $ulid, '_pointsToNextItems' => 'yes'])],
        'date as int' => [keysetCursorOf(['submitted_at' => 20261018, 'id' => $ulid, '_pointsToNextItems' => true])],
        'feb 31' => [keysetCursorOf(['submitted_at' => '2026-02-31T10:00:00.000000Z', 'id' => $ulid, '_pointsToNextItems' => true])],
        'year zero' => [keysetCursorOf(['submitted_at' => '0000-01-01T10:00:00.000000Z', 'id' => $ulid, '_pointsToNextItems' => true])],
        'hour 25' => [keysetCursorOf(['submitted_at' => '2026-10-18T25:00:00.000000Z', 'id' => $ulid, '_pointsToNextItems' => true])],
        'offset 99' => [keysetCursorOf(['submitted_at' => '2026-10-18T10:00:00+99:00', 'id' => $ulid, '_pointsToNextItems' => true])],
        'date garbage' => [keysetCursorOf(['submitted_at' => 'yesterday', 'id' => $ulid, '_pointsToNextItems' => true])],
        'date injection' => [keysetCursorOf(['submitted_at' => "2026-10-18' OR 1=1 --", 'id' => $ulid, '_pointsToNextItems' => true])],
        'id not ulid' => [keysetCursorOf(['submitted_at' => $date, 'id' => 'abc', '_pointsToNextItems' => true])],
        'id array' => [keysetCursorOf(['submitted_at' => $date, 'id' => [$ulid], '_pointsToNextItems' => true])],
        'array value' => [['a']],
        'integer value' => [5],
    ];
});

it('lets an absent or empty cursor through as "first page"', function () {
    // Given an empty value ("?cursor=" becomes null in a request, and the list requests mark the field nullable)
    // When validated on its own
    // Then the rule does not run on an empty string; the controller reads no cursor and serves page one
    expect(keysetCursorPasses(''))->toBeTrue();
});
