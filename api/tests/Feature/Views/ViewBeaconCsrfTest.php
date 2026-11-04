<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

it('excludes the view beacon path from CSRF verification', function () {
    // Given the middleware Sanctum runs for a stateful origin and the framework's base class
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When the except list is asked about the beacon path
    // Then it is excluded for both
    expect(viewCsrfExcepts(ValidateCsrfToken::class, "/api/v1/products/{$id}/view"))->toBeTrue();
    expect(viewCsrfExcepts(PreventRequestForgery::class, "/api/v1/products/{$id}/view"))->toBeTrue();
});

it('keeps the sibling product routes under CSRF protection and the unsubscribe exclusion intact', function (string $method, string $uri) {
    // Given the except list
    // When it is asked about other routes
    // Then none of them is excluded
    expect(viewCsrfExcepts(ValidateCsrfToken::class, $uri, $method))->toBeFalse()->and(viewCsrfExcepts(PreventRequestForgery::class, $uri, $method))->toBeFalse();
})->with([
    ['PUT', '/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/vote'],
    ['POST', '/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/comments'],
    ['POST', '/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/submit'],
    ['POST', '/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/view/extra'],
    ['POST', '/api/v1/products'],
    ['POST', '/api/v1/auth/login'],
]);

it('still excludes the unsubscribe path alongside the beacon', function () {
    // Given both exclusions registered in bootstrap/app.php
    // When the unsubscribe path is asked about
    // Then it is still excluded
    expect(viewCsrfExcepts(PreventRequestForgery::class, '/api/v1/email/unsubscribe'))->toBeTrue();
});
