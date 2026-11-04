<?php

beforeEach(function () {
    // Pin the config so a developer's .env cannot change the result.
    config(['cors.allowed_origins' => ['http://localhost:5173']]);
});

it('lets the configured web origin call the API with credentials', function () {
    // Given a preflight request from the React site
    // When the browser asks
    $response = $this->options('/api/v1/me', [], [
        'Origin' => 'http://localhost:5173',
        'Access-Control-Request-Method' => 'GET',
    ]);

    // Then exactly that origin is allowed, with credentials
    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    $response->assertHeader('Access-Control-Max-Age', '600');
});

it('exposes rate limit headers and allows only the listed request headers', function () {
    // Given the CORS config
    // When it is read
    // Then headers are explicit, not a wildcard
    expect(config('cors.allowed_headers'))->toBe(['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Device-Id']);
    expect(config('cors.exposed_headers'))->toBe(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Content-Disposition']);
});

it('does not allow other origins', function () {
    // Given a preflight request from an unknown site
    // When the browser asks
    $response = $this->options('/api/v1/me', [], [
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'GET',
    ]);

    // Then the unknown origin is never echoed back. With a single configured origin the CORS
    // library answers with that fixed origin, so the browser rejects the mismatch.
    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://evil.example')
        ->toBeIn([null, 'http://localhost:5173']);
});
