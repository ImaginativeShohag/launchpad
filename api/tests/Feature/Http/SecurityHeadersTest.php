<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->get('api/v1/auth/_probe', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->get('api/v1/admin/_probe', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->post('api/v1/public/_validate', fn () => response()->json(['message' => 'bad'], 422));
    Route::middleware('api')->get('api/v1/public/_probe', fn () => response()->json(['ok' => true]));
});

it('adds the baseline security headers to every API response', function () {
    // Given any API route
    // When it is requested
    $response = $this->getJson('/api/v1/public/_probe');

    // Then the baseline headers are present
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('forbids caching of authentication responses', function () {
    // Given an auth route
    // When it is requested
    $response = $this->getJson('/api/v1/auth/_probe');

    // Then caching is disabled
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('forbids caching of admin responses', function () {
    // Given an admin route
    // When it is requested
    $response = $this->getJson('/api/v1/admin/_probe');

    // Then caching is disabled
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not add no-store to public responses', function () {
    // Given a public route
    // When it is requested
    $response = $this->getJson('/api/v1/public/_probe');

    // Then no-store is absent
    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('adds the baseline headers to 404 and 422 responses', function () {
    // Given an unknown route and a failing validation route
    // When they are requested
    $notFound = $this->getJson('/api/v1/public/does-not-exist');
    $invalid = $this->postJson('/api/v1/public/_validate');

    // Then both carry the baseline headers
    $notFound->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
    $notFound->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $invalid->assertStatus(422)->assertHeader('X-Content-Type-Options', 'nosniff');
    $invalid->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('forbids caching of comment writes but not of the public comment reads', function () {
    // Given probe routes shaped like the comment routes
    Route::middleware('api')->post('api/v1/comments/_probe', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->patch('api/v1/comments/_probe', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->delete('api/v1/comments/_probe', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->get('api/v1/comments/_probe/replies', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->post('api/v1/products/_probe/comments', fn () => response()->json(['ok' => true]));

    // When they are requested
    // Then the writes are no-store and the GET read is not
    foreach (['post' => '/api/v1/comments/_probe', 'patch' => '/api/v1/comments/_probe', 'delete' => '/api/v1/comments/_probe', 'post ' => '/api/v1/products/_probe/comments'] as $method => $uri) {
        expect($this->json(trim($method), $uri)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
    expect($this->getJson('/api/v1/comments/_probe/replies')->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('forbids caching of follow writes and report writes but not of the public profile and topic reads', function () {
    // Given probe routes shaped like the follow, report, profile and topic routes
    Route::middleware('api')->put('api/v1/users/_probe/follow', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->delete('api/v1/users/_probe/follow', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->put('api/v1/topics/_probe/follow', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->delete('api/v1/topics/_probe/follow', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->post('api/v1/comments/_probe/report', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->post('api/v1/products/_probe/report', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->get('api/v1/users/_probe', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->get('api/v1/topics', fn () => response()->json(['ok' => true]));

    // When they are requested
    // Then every write is no-store and private, and the two public GET reads are not
    foreach ([['put', '/api/v1/users/_probe/follow'], ['delete', '/api/v1/users/_probe/follow'], ['put', '/api/v1/topics/_probe/follow'], ['delete', '/api/v1/topics/_probe/follow'], ['post', '/api/v1/comments/_probe/report'], ['post', '/api/v1/products/_probe/report']] as [$method, $uri]) {
        expect($this->json($method, $uri)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
    expect($this->getJson('/api/v1/users/_probe')->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($this->getJson('/api/v1/topics')->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('forbids caching of the notification routes', function () {
    // Given probe routes shaped like the bell routes
    Route::middleware('api')->get('api/v1/me/notifications', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->get('api/v1/me/notifications/unread-count', fn () => response()->json(['ok' => true]));
    Route::middleware('api')->post('api/v1/me/notifications/read', fn () => response()->json(['ok' => true]));

    // When they are requested
    // Then every response is no-store and private
    foreach ([['get', '/api/v1/me/notifications'], ['get', '/api/v1/me/notifications/unread-count'], ['post', '/api/v1/me/notifications/read']] as [$method, $uri]) {
        expect($this->json($method, $uri)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('forbids caching of the email preference and unsubscribe routes', function () {
    // Given the real routes, requested by a guest (401 for the member routes, 403 for an unsigned unsubscribe)
    // When they are requested
    // Then every response, whatever its status, is no-store and private
    foreach ([['get', '/api/v1/me/email-preferences'], ['patch', '/api/v1/me/email-preferences'], ['post', '/api/v1/email/unsubscribe']] as [$method, $uri]) {
        expect($this->json($method, $uri)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('forbids caching of the eight dashboard reads, whatever their status', function () {
    // Given the real routes, requested by a guest (401) with a well-formed ULID
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When all eight are requested
    // Then every response is no-store and private
    foreach (['me', 'staff'] as $prefix) {
        foreach (['', '/series', '/rank', '/referrers'] as $suffix) {
            expect($this->getJson("/api/v1/{$prefix}/products/{$id}/dashboard{$suffix}")->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        }
    }
});

it('forbids caching of the export routes including the download, whatever their status', function () {
    // Given the real routes, requested by a guest (401) with a well-formed ULID
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When they are requested
    // Then every response is no-store and private
    foreach ([['post', "/api/v1/me/products/{$id}/exports"], ['get', "/api/v1/me/products/{$id}/exports"], ['get', "/api/v1/me/products/{$id}/exports/{$id}"], ['get', "/api/v1/me/products/{$id}/exports/{$id}/download"]] as [$method, $uri]) {
        expect($this->json($method, $uri)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('forbids caching of the six anti-cheat staff reads, whatever their status', function () {
    // Given the real routes, requested by a guest (401) with a well-formed ULID
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When they are requested
    // Then every response is no-store and private (through the staff/* prefix)
    foreach (['risk-signals', "risk-signals/{$id}", 'risk/members', "risk/members/{$id}", "products/{$id}/vote-integrity", "products/{$id}/votes"] as $path) {
        expect($this->getJson("/api/v1/staff/{$path}")->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});
