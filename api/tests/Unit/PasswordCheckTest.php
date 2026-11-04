<?php

use App\Support\PasswordCheck;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;

it('verifies a password against a hash without ever throwing', function (string|Closure|null $hash, string $plain, bool $expected) {
    // Given a stored hash (or none) and a candidate password
    $hash = $hash instanceof Closure ? $hash() : $hash;

    // When it is verified
    $result = PasswordCheck::verify($plain, $hash);

    // Then only a genuine match is true
    expect($result)->toBe($expected);
})->with(function () {
    yield 'null hash' => [null, 'secret-passphrase', false];
    yield 'empty hash' => ['', 'secret-passphrase', false];
    yield 'correct' => [fn () => Hash::make('secret-passphrase'), 'secret-passphrase', true];
    yield 'wrong' => [fn () => Hash::make('secret-passphrase'), 'other-passphrase', false];
    yield 'foreign algorithm' => [fn () => Hash::driver('argon2id')->make('secret-passphrase'), 'secret-passphrase', false];
    yield 'garbage' => ['garbage', 'secret-passphrase', false];
});

it('reports a malformed hash once per hour, not on every check', function () {
    // Given a malformed stored hash
    Exceptions::fake();

    // When it is verified twice
    $first = PasswordCheck::verify('secret-passphrase', 'garbage');
    $second = PasswordCheck::verify('secret-passphrase', 'garbage');

    // Then both are false but only one report is made
    expect($first)->toBeFalse()->and($second)->toBeFalse();
    Exceptions::assertReportedCount(1);
});
