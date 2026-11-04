<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * Asks a CSRF middleware of class $class whether a path is excluded (the unit-test harness skips CSRF, so a request cannot
 * show a 419). The probe really is a subclass of $class.
 */
function csrfExcepts(string $class, string $uri, string $method = 'POST'): bool
{
    $probe = match ($class) {
        ValidateCsrfToken::class => new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            public function excepted(Request $request): bool
            {
                return $this->inExceptArray($request);
            }
        },
        PreventRequestForgery::class => new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            public function excepted(Request $request): bool
            {
                return $this->inExceptArray($request);
            }
        },
    };

    expect($probe)->toBeInstanceOf($class);

    return $probe->excepted(Request::create($uri, $method));
}

it('excludes exactly the unsubscribe path from CSRF verification', function () {
    // Given the middleware Sanctum runs for a stateful origin and the framework's base class
    // When the except list is asked about the unsubscribe path
    // Then it is excluded for both
    expect(csrfExcepts(ValidateCsrfToken::class, '/api/v1/email/unsubscribe'))->toBeTrue();
    expect(csrfExcepts(PreventRequestForgery::class, '/api/v1/email/unsubscribe'))->toBeTrue();
});

it('keeps every sibling route under CSRF protection', function (string $uri) {
    // Given the except list
    // When it is asked about routes that are not the unsubscribe path
    // Then none is excluded
    expect(csrfExcepts(ValidateCsrfToken::class, $uri))->toBeFalse()->and(csrfExcepts(PreventRequestForgery::class, $uri))->toBeFalse();
})->with([
    '/api/v1/auth/email/change/confirm', '/api/v1/auth/email/change/cancel', '/api/v1/auth/email/verify', '/api/v1/auth/login',
    '/api/v1/me/email-preferences', '/api/v1/email/unsubscribe/extra', '/api/v1/email', '/api/v1/me/notifications/read',
]);
