<?php

use App\Models\User;

it('serves the Sanctum CSRF cookie at the root, outside /api/v1', function () {
    // Given a web client
    // When it asks for the CSRF cookie
    $response = spa()->get('/sanctum/csrf-cookie');

    // Then it gets 204 and a readable XSRF-TOKEN cookie, and the cookie is not offered under /api/v1
    $response->assertNoContent()->assertCookie('XSRF-TOKEN', null, false);
    expect($response->getCookie('XSRF-TOKEN', false)->isHttpOnly())->toBeFalse();
    spa()->get('/api/v1/sanctum/csrf-cookie')->assertNotFound();
});

it('issues an HttpOnly, SameSite=Lax session cookie that lasts two hours', function () {
    // Given a member and a fixed clock
    $this->freezeTime();
    User::factory()->create(['email' => 'amina@example.com']);

    // When they sign in from the web app
    $response = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery']);

    // Then the session cookie is HttpOnly, Lax and expires after the 120-minute lifetime
    $cookie = $response->getCookie(config('session.cookie'), false);
    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getExpiresTime())->toBe(now()->addMinutes(120)->getTimestamp());
});
