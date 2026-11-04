<?php

use App\Rules\KeysetCursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

function keysetUuidCursorOf(mixed $payload): string
{
    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
}

function keysetUuidCursorPasses(mixed $value, array $shape = ['created_at' => 'date', 'id' => 'uuid']): bool
{
    return Validator::make(['cursor' => $value], ['cursor' => [new KeysetCursor($shape)]])->passes();
}

function keysetUuidCursorWith(mixed $id): string
{
    return keysetUuidCursorOf(['created_at' => '2026-10-21T10:00:00.000000Z', 'id' => $id, '_pointsToNextItems' => true]);
}

it('accepts a UUID of any version for a uuid column', function (string $uuid) {
    // Given a cursor whose id is a UUID
    // When it is validated
    // Then it passes
    expect(keysetUuidCursorPasses(keysetUuidCursorWith($uuid)))->toBeTrue();
})->with([
    'v7' => fn () => (string) Str::uuid7(),
    'v4' => fn () => (string) Str::uuid(),
    'nil-ish v1 shape' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
]);

it('refuses anything that is not a UUID string for a uuid column', function (mixed $id) {
    // Given a cursor whose id has the wrong shape
    // When it is validated
    // Then it fails
    expect(keysetUuidCursorPasses(keysetUuidCursorWith($id)))->toBeFalse();
})->with([
    'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    '35 chars' => '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2',
    'braces' => '{0190A1B2-C3D4-7E5F-8A6B-7C8D9E0F1A2B}',
    'null' => [null],
    'array' => [['x']],
    'int' => 5,
    'no dashes' => '0190a1b2c3d47e5f8a6b7c8d9e0f1a2b',
    'trailing newline' => "0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b\n",
]);

it('refuses the wrong key order and keeps the other shapes unchanged', function () {
    // Given a wrong order and a ulid shape
    $uuid = (string) Str::uuid7();
    $ulid = (string) Str::ulid();

    // When validated
    // Then the order is enforced and the ulid shape still passes with a ulid
    expect(keysetUuidCursorPasses(keysetUuidCursorOf(['id' => $uuid, 'created_at' => '2026-10-21T10:00:00.000000Z', '_pointsToNextItems' => true])))->toBeFalse();
    expect(keysetUuidCursorPasses(keysetUuidCursorOf(['created_at' => '2026-10-21T10:00:00.000000Z', 'id' => $ulid, '_pointsToNextItems' => true]), ['created_at' => 'date', 'id' => 'ulid']))->toBeTrue();
});
