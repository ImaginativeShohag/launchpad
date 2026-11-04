<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    Route::middleware('api')->get('api/v1/_probe', fn () => response()->json([
        'device' => request()->attributes->get('device_id'),
    ]));
});

it('issues a device cookie once and reuses it afterwards', function () {
    // Given a first-time visitor without a cookie
    // When they call the API
    $first = $this->getJson('/api/v1/_probe');

    // Then an HttpOnly lp_did cookie holding a ULID is issued
    $first->assertOk()->assertCookie('lp_did', null, false);
    $cookie = $first->getCookie('lp_did', false);
    expect($cookie->isHttpOnly())->toBeTrue();
    expect(Str::isUlid($cookie->getValue()))->toBeTrue();

    // When they come back with that cookie
    $second = $this->withCredentials()->withUnencryptedCookie('lp_did', $cookie->getValue())->getJson('/api/v1/_probe');

    // Then the same id is used and no new cookie is issued
    expect($second->json('device'))->toBe($cookie->getValue());
    $second->assertCookieMissing('lp_did');
});

it('accepts the install id a mobile app sends and does not set a cookie', function () {
    // Given a mobile client with an install id header
    $id = 'ios-install-0123456789abcdef';

    // When it calls the API
    $response = $this->withHeader('X-Device-Id', $id)->getJson('/api/v1/_probe');

    // Then the header value is used as the device id
    expect($response->json('device'))->toBe($id);
    $response->assertCookieMissing('lp_did');
});

it('ignores a malformed device id and issues a fresh one', function () {
    // Given a header with junk characters
    // When the API is called
    $response = $this->withHeader('X-Device-Id', '<script>alert(1)</script>')->getJson('/api/v1/_probe');

    // Then a new valid id is issued
    expect(Str::isUlid($response->json('device')))->toBeTrue();
    $response->assertCookie('lp_did', null, false);
});

it('keeps a valid cookie when the header is malformed', function () {
    // Given a valid cookie and a junk header
    $cookieId = '01jabcdefghjkmnpqrstvwxyz0';

    // When the API is called
    $response = $this->withCredentials()
        ->withUnencryptedCookie('lp_did', $cookieId)
        ->withHeader('X-Device-Id', '<script>alert(1)</script>')
        ->getJson('/api/v1/_probe');

    // Then the cookie id is used and no new cookie is issued
    expect($response->json('device'))->toBe($cookieId);
    $response->assertCookieMissing('lp_did');
});

it('rejects a header value with a trailing newline', function () {
    // Given a header that only matches when "$" tolerates a trailing newline
    // When the API is called
    $response = $this->withHeader('X-Device-Id', "abcdefghijklmnop\n")->getJson('/api/v1/_probe');

    // Then a fresh id is issued instead
    expect(Str::isUlid($response->json('device')))->toBeTrue();
    $response->assertCookie('lp_did', null, false);
});

it('sets the device cookie flags: HttpOnly, SameSite=Lax, path / and a two-year expiry', function () {
    // Given a fixed clock
    $this->freezeTime();

    // When a first-time visitor calls the API
    $cookie = $this->getJson('/api/v1/_probe')->getCookie('lp_did', false);

    // Then the cookie carries the intended flags
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getPath())->toBe('/');
    expect($cookie->getExpiresTime())->toBe(now()->addYears(2)->getTimestamp());
});

it('derives the Secure flag from session.secure, falling back to the request scheme when it is unset', function (mixed $setting, string $url, bool $secure) {
    // Given the session.secure setting and a request URL
    config(['session.secure' => $setting]);

    // When a first-time visitor calls the API
    $cookie = $this->getJson($url.'/api/v1/_probe')->getCookie('lp_did', false);

    // Then an explicit setting wins and null follows whether the request arrived over https
    expect($cookie->isSecure())->toBe($secure);
})->with([
    'null over http' => [null, 'http://localhost', false],
    'null over https' => [null, 'https://localhost', true],
    'true over http' => [true, 'http://localhost', true],
    'false over https' => [false, 'https://localhost', false],
]);
