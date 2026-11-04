<?php

use App\Actions\AntiCheat\Fingerprint;

it('shows the first eight characters of a 64 hex hash', function () {
    // Given a stored HMAC
    $hash = 'abcdef0123456789'.str_repeat('0', 48);

    // When the fingerprint is taken
    // Then only the first eight characters remain
    expect(Fingerprint::of($hash))->toBe('abcdef01');
});

it('has no fingerprint for a purged or malformed value', function (?string $value) {
    // Given a null (purged), empty, short, long or non-hex value
    // When the fingerprint is taken
    // Then it is null
    expect(Fingerprint::of($value))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    '63 chars' => [str_repeat('a', 63)],
    '65 chars' => [str_repeat('a', 65)],
    'uppercase hex' => [str_repeat('A', 64)],
    'non hex' => [str_repeat('g', 64)],
    'trailing newline' => [str_repeat('a', 64)."\n"],
]);
