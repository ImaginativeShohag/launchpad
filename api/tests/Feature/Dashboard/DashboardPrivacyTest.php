<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(dashNow()));

/** @return list<string> every key (at any depth) of a decoded JSON value */
function dashPrivacyKeys(mixed $value): array
{
    $keys = [];
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...dashPrivacyKeys($child));
        }
    }

    return $keys;
}

it('exposes no view, visit, source or conversion number on any public product read', function () {
    // Given a product with views, referrers and rank data, a signed-in stranger and a guest
    $maker = User::factory()->create(['handle' => 'privacymaker']);
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'votes_count' => 3, 'name' => 'Privacy Probe', 'tagline' => 'Probe']);
    dashBucket($product, '2026-10-21 09:00:00', 3, 77);
    dashReferrer($product, DASH_DAY, 'x', 77);
    dashSnapshot($product, '2026-10-21 09:00:00', 1);
    $reads = [
        "/api/v1/products/{$product->slug}", '/api/v1/today', '/api/v1/days/2026-10-21', '/api/v1/leaderboards?period=week',
        '/api/v1/users/privacymaker/products', '/api/v1/search?q=privacy', "/api/v1/products/{$product->slug}/comments",
    ];

    foreach ([true, false] as $signedIn) {
        $signedIn ? Sanctum::actingAs(User::factory()->create()) : app('auth')->forgetGuards();

        foreach ($reads as $uri) {
            // When the public read is requested
            $response = $this->getJson($uri)->assertOk();

            // Then no analytics key is present at any depth, and the seeded numbers are nowhere in the body
            $keys = dashPrivacyKeys($response->json());
            expect(array_intersect($keys, ['views', 'visits', 'source', 'sources', 'conversion', 'referrers', 'cumulative_views', 'by_category', 'top_source']))->toBe([])
                ->and($response->getContent())->not->toContain('"views"')->not->toContain('x.com');
        }
    }
});

it('gives a member who views someone else\'s product the uniform 404 on every dashboard route', function () {
    // Given a live product of another maker with data, and a signed-in member
    $product = dashProduct(DASH_DAY);
    dashBucket($product, '2026-10-21 09:00:00', 1, 50);
    Sanctum::actingAs(User::factory()->create());
    $missing = $this->getJson('/api/v1/me/products/01ARZ3NDEKTSV4RRFFQ69G5FAV/dashboard');

    // When every maker route is read for it
    foreach (['', 'series', 'rank', 'referrers'] as $endpoint) {
        $response = dashApi('me', $product->id, $endpoint);

        // Then it is byte-identical to the answer for an id that does not exist, and carries no number
        $response->assertNotFound();
        expect($response->getContent())->toBe($missing->getContent())->not->toContain('50');
    }
});
