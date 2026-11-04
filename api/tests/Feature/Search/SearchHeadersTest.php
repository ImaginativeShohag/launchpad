<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => searchLive('Quokka Tool'));

it('varies on the credentials always, and is no-store only for a known viewer', function () {
    // Given a member
    $member = User::factory()->create();
    $token = $member->createToken('t')->plainTextToken;

    // When a guest, a bearer caller and an invalid bearer caller search
    $guest = searchApi('q=quokka')->assertOk();
    app('auth')->forgetGuards();
    $bearer = $this->withToken($token)->getJson('/api/v1/search?q=quokka')->assertOk();
    app('auth')->forgetGuards();
    $junk = $this->withToken('1|nonsense')->getJson('/api/v1/search?q=quokka')->assertOk();

    // Then all vary; only the known viewer's answer is no-store, private; the guest keeps Laravel's default
    foreach ([$guest, $bearer, $junk] as $response) {
        expect($response->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
    }
    expect($guest->headers->get('Cache-Control'))->toBe('no-cache, private')->and($junk->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($bearer->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('from a stateful origin sends the session and XSRF cookies like every public read, and no-store for a member', function () {
    // Given a web client from the stateful SPA origin
    // When a guest and then a member search
    $guest = spa()->getJson('/api/v1/search?q=quokka')->assertOk();
    app('auth')->forgetGuards();
    Sanctum::actingAs(User::factory()->create());
    $member = spa()->getJson('/api/v1/search?q=quokka')->assertOk();

    // Then the framework cookies come with the stateful origin, and only the member's answer is no-store
    expect(collect($guest->headers->getCookies())->map->getName()->all())->toBe(['lp_did', 'XSRF-TOKEN', config('session.cookie')]);
    expect($guest->headers->get('Cache-Control'))->not->toContain('no-store')->and($member->headers->get('Cache-Control'))->toContain('no-store');
});

it('is a public read, not under an authenticated prefix, and the middleware does not name it', function () {
    // Given the registered routes
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/search');

    // When its middleware and the security-headers middleware are inspected
    // Then there is no auth on the route and the no-store prefix list does not name it
    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->not->toContain('auth:sanctum')->not->toContain('active')->not->toContain('verified.api');
    expect(file_get_contents(app_path('Http/Middleware/SecurityHeaders.php')))->not->toContain('api/v1/search');
});
