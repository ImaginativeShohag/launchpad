<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Route;

it('serves exactly the three form limits to a guest', function () {
    // Given the default settings
    // When a guest reads the config
    $response = $this->getJson('/api/v1/config');

    // Then only the three non-sensitive limits come back
    $response->assertOk()->assertExactJson(['data' => ['max_topics' => 3, 'max_screenshots' => 6, 'max_launches_per_day' => 2]]);
});

it('follows the admin-editable settings', function () {
    // Given the limits changed by an admin
    $admin = User::factory()->role(Role::Admin)->create();
    app(Settings::class)->set(SettingKey::ProductMaxTopics, 5, $admin);
    app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, 4, $admin);

    // When read
    // Then the new values are served
    $this->getJson('/api/v1/config')->assertJsonPath('data.max_topics', 5)->assertJsonPath('data.max_launches_per_day', 4);
});

it('is browser-cacheable for a minute but never shared-cacheable or no-store, and varies on Origin', function () {
    // Given the route table
    $middleware = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/config')->gatherMiddleware();

    // When the config is read from the web origin
    $response = $this->getJson('/api/v1/config', ['Origin' => 'http://localhost:5173']);

    // Then no auth guard is on the route, only the three keys are served, and the headers are private with a short max-age
    expect($middleware)->not->toContain('auth:sanctum')->not->toContain('active');
    expect(array_keys($response->json('data')))->toBe(['max_topics', 'max_screenshots', 'max_launches_per_day'])->and(array_keys($response->json()))->toBe(['data']);
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60')->not->toContain('no-store')->not->toContain('public')->not->toContain('s-maxage');
    expect($response->headers->get('Vary'))->toContain('Origin');
});
