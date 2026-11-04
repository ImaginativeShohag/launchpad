<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

function docsRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'docs/')) {
            $routes[$route->methods()[0].' '.$route->uri()] = $route->gatherMiddleware();
        }
    }
    ksort($routes);

    return $routes;
}

it('has exactly two read-only docs routes, both behind the docs limiter and the access check', function () {
    // Given the registered routes
    // When the ones under docs/ are listed
    $routes = docsRoutes();

    // Then they are the UI and the spec, and both carry the limiter and the environment/gate check
    expect(array_keys($routes))->toBe(['GET docs/api', 'GET docs/api.json']);
    foreach ($routes as $middleware) {
        expect($middleware)->toContain('throttle:api-docs')
            ->and($middleware)->toContain(RestrictedDocsAccess::class);
    }
});

it('denies the docs outside the local environment', function (string $uri) {
    // Given the testing environment and no viewApiDocs gate
    // When the docs are requested
    $response = $this->get($uri);

    // Then access is forbidden and nothing is cached
    $response->assertForbidden();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['/docs/api', '/docs/api.json']);

it('serves the OpenAPI spec in the local environment', function () {
    // Given the local environment
    $this->app['env'] = 'local';

    // When the spec is requested
    $response = $this->getJson('/docs/api.json');

    // Then it is OpenAPI 3.1, rooted at /api/v1, and describes real routes but not test probes
    $response->assertOk();
    expect($response->json('openapi'))->toStartWith('3.1.')
        ->and($response->json('servers.0.url'))->toEndWith('/api/v1')
        ->and(array_keys($response->json('paths')))->toContain('/search')
        ->and(json_encode($response->json('paths')))->not->toContain('_limit');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('serves the docs page in the local environment', function () {
    // Given the local environment
    $this->app['env'] = 'local';

    // When the page is requested
    // Then it renders
    $this->get('/docs/api')->assertOk();
});

it('ignores array, object and null query input on the docs routes', function (string $query) {
    // Given the local environment
    $this->app['env'] = 'local';

    // When the spec is requested with odd query input
    // Then it is still served, never a 500
    $this->getJson('/docs/api.json?'.$query)->assertOk();
})->with(['a[]=1', 'a[b]=1', 'a=']);

it('limits the docs to 30 per minute per ip', function () {
    // Given the local environment and thirty requests from one address
    $this->app['env'] = 'local';
    RateLimiter::clear('api-docs:127.0.0.1');
    foreach (range(1, 30) as $_) {
        $this->getJson('/docs/api.json')->assertOk();
    }

    // When a 31st arrives
    // Then it is throttled with a Retry-After header
    $this->getJson('/docs/api.json')->assertStatus(429)->assertHeader('Retry-After');
});
