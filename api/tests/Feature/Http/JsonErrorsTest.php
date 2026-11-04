<?php

it('answers unknown API routes with a JSON 404 and no internals', function () {
    // Given no route exists at this path

    // When a client requests it
    $response = $this->getJson('/api/v1/does-not-exist');

    // Then it gets a short JSON 404
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('answers an unauthenticated request to a protected route with a JSON 401', function () {
    // Given a protected route
    // When an unauthenticated client asks for the current user
    $response = $this->getJson('/api/v1/me');

    // Then it is rejected as a JSON 401
    $response->assertUnauthorized();
    expect($response->headers->get('content-type'))->toContain('application/json');
});
