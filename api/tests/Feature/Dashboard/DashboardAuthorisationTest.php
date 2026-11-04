<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(dashNow()));

const DASH_ENDPOINTS = ['', 'series', 'rank', 'referrers'];

/** Query strings that would be 422 for an authorised caller: a stranger must never get that far. */
function dashBadQueries(string $endpoint): array
{
    return match ($endpoint) {
        'series' => [['granularity' => ['x']], ['from' => 'garbage'], ['to' => ['1']]],
        'referrers' => [['from' => 'garbage'], ['to' => ['1']]],
        default => [],
    };
}

it('shows the maker the exact data shape of every endpoint', function () {
    // Given a maker with a launch today that has data everywhere
    $product = dashMakerProduct(DASH_DAY, ['votes_count' => 4, 'hot_score' => 2.0]);
    dashBucket($product, '2026-10-21 09:00:00', 4, 8);
    dashReferrer($product, DASH_DAY, 'x', 8);
    dashSnapshot($product, '2026-10-21 09:00:00', 1);

    // When each endpoint is read
    $summary = dashApi('me', $product->id)->assertOk();
    $series = dashApi('me', $product->id, 'series')->assertOk();
    $rank = dashApi('me', $product->id, 'rank')->assertOk();
    $referrers = dashApi('me', $product->id, 'referrers')->assertOk();

    // Then each is wrapped in `data` with the spec's keys and types
    expect(array_keys($summary->json('data')))->toBe(['product_id', 'name', 'slug', 'status', 'phase', 'launch_date', 'taken_down', 'views', 'votes', 'comments', 'conversion', 'current_rank', 'best_rank', 'final_rank', 'top_source', 'timeline_available', 'as_of'])
        ->and($summary->json('data.product_id'))->toBe($product->id)->and($summary->json('data.views'))->toBeInt()->and($summary->json('data.conversion'))->toBe(0.5)
        ->and($summary->json('data.top_source'))->toBe(['source' => 'x', 'category' => 'social', 'visits' => 8])
        ->and(array_keys($series->json('data')))->toBe(['granularity', 'from', 'to', 'points'])
        ->and(array_keys($series->json('data.points.0')))->toBe(['t', 'votes', 'views', 'cumulative_votes', 'cumulative_views'])
        ->and(array_keys($rank->json('data')))->toBe(['launch_date', 'phase', 'frozen', 'final_rank', 'current_rank', 'best_rank', 'best_rank_at', 'timeline_available', 'interval_minutes', 'points', 'outside_top'])
        ->and(array_keys($rank->json('data.points.0')))->toBe(['t', 'rank', 'score'])
        ->and(array_keys($referrers->json('data')))->toBe(['from', 'to', 'total', 'sources', 'by_category'])
        ->and(array_keys($referrers->json('data.sources.0')))->toBe(['source', 'category', 'visits', 'share'])
        ->and(array_keys($referrers->json('data.by_category.0')))->toBe(['category', 'visits', 'share']);
});

it('shows a maker every non-deleted product in any status', function (string $status) {
    // Given a maker with a product in the status
    $maker = signedInMember();
    $product = match ($status) {
        'draft' => Product::factory()->create(['maker_id' => $maker->id]),
        'pending' => Product::factory()->pending()->create(['maker_id' => $maker->id]),
        'rejected' => Product::factory()->rejected()->create(['maker_id' => $maker->id]),
        'scheduled' => Product::factory()->scheduled('2026-10-25')->create(['maker_id' => $maker->id]),
        'taken_down' => Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'launch_date' => '2026-10-19']),
    };
    dashBucket(Product::factory()->live(DASH_DAY)->create(), '2026-10-21 10:00:00', 9, 9);

    // When all four endpoints are read
    foreach (DASH_ENDPOINTS as $endpoint) {
        $response = dashApi('me', $product->id, $endpoint)->assertOk();

        // Then they answer 200 with data
        expect($response->json('data'))->toBeArray();
    }
    $summary = dashApi('me', $product->id)->json('data');
    expect($summary['phase'])->toBe($status === 'taken_down' ? 'after' : 'before_launch')->and($summary['taken_down'])->toBe($status === 'taken_down')
        ->and($summary['views'])->toBe(0)->and($summary['votes'])->toBe(0);
})->with(['draft', 'pending', 'rejected', 'scheduled', 'taken_down']);

it('answers every stranger case with the one 404 and runs no stats query, even with invalid parameters', function (string $case) {
    // Given a product that is not the caller's, in the situation of the case
    $maker = User::factory()->create();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);
    $draft = Product::factory()->create(['maker_id' => $maker->id]);
    $deleted = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'deleted_at' => now()]);
    $target = match ($case) {
        'another maker' => $product->id,
        'reviewer' => $product->id,
        'admin' => $product->id,
        'unknown ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'not a ulid' => 'not-a-ulid',
        'long id' => str_repeat('a', 200),
        'nul byte' => '%00',
        'soft deleted' => $deleted->id,
        'hidden draft of another' => $draft->id,
    };
    match ($case) {
        'reviewer' => Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create()),
        'admin' => Sanctum::actingAs(User::factory()->role(Role::Admin)->create()),
        default => Sanctum::actingAs(User::factory()->create()),
    };
    $statements = [];

    // When every endpoint is read with valid and with invalid parameters
    $queries = dashStatsQueries(function () use ($target, &$statements): void {
        foreach (DASH_ENDPOINTS as $endpoint) {
            foreach ([[], ...dashBadQueries($endpoint)] as $query) {
                $statements[] = dashApi('me', $target, $endpoint, $query);
            }
        }
    });

    // Then every answer is the identical 404 body and no stats table was read
    expect($statements)->not->toBeEmpty();
    foreach ($statements as $response) {
        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
    expect($queries)->toBe([]);
})->with(['another maker', 'reviewer', 'admin', 'unknown ulid', 'not a ulid', 'long id', 'nul byte', 'soft deleted', 'hidden draft of another']);

it('answers a guest 401 on all eight routes', function () {
    // Given a product and nobody signed in
    $product = dashProduct();

    // When all eight are read
    // Then each is 401
    foreach (['me', 'staff'] as $prefix) {
        foreach (DASH_ENDPOINTS as $endpoint) {
            dashApi($prefix, $product->id, $endpoint)->assertUnauthorized();
        }
    }
});

it('refuses an unverified member 403 and a suspended maker 403 before anything else, equally for existing and missing ids', function (string $state, string $code) {
    // Given a member in the state who owns a product
    $member = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $member->id]);
    $state === 'suspended' ? $member->forceFill(['suspended_at' => now()])->save() : $member->forceFill(['email_verified_at' => null])->save();

    // When all eight routes are read for the existing and a missing id
    foreach (['me', 'staff'] as $prefix) {
        foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            foreach (DASH_ENDPOINTS as $endpoint) {
                // Then the guard's 403 is the answer
                dashApi($prefix, $id, $endpoint)->assertForbidden()->assertJsonPath('code', $code);
            }
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('lets reviewers and admins read live, taken-down, rejected and pending products on the staff prefix', function (Role $role, string $status) {
    // Given a product in the status and a staff member
    $product = match ($status) {
        'live' => dashProduct(),
        'taken_down' => Product::factory()->takenDown()->create(['launch_date' => '2026-10-19']),
        'rejected' => Product::factory()->rejected()->create(),
        'pending' => Product::factory()->pending()->create(),
    };
    Sanctum::actingAs(User::factory()->role($role)->create());

    // When the four staff routes are read
    foreach (DASH_ENDPOINTS as $endpoint) {
        // Then each is 200
        dashApi('staff', $product->id, $endpoint)->assertOk();
    }
})->with([[Role::Reviewer], [Role::Admin]])->with(['live', 'taken_down', 'rejected', 'pending']);

it('treats a draft as missing for staff and an unknown id as missing', function () {
    // Given a draft and staff
    $draft = Product::factory()->create();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When read on the staff prefix
    foreach (DASH_ENDPOINTS as $endpoint) {
        // Then the draft, a soft-deleted product and an unknown id are the same 404
        dashApi('staff', $draft->id, $endpoint)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        dashApi('staff', Product::factory()->pending()->create(['deleted_at' => now()])->id, $endpoint)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        dashApi('staff', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $endpoint)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        dashApi('staff', 'not-a-ulid', $endpoint)->assertNotFound();
    }
});

it('answers a non-staff caller on the staff prefix 403 for existing and unknown ids alike, also with invalid parameters, and a maker for their own product', function () {
    // Given a maker with a live product
    $maker = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);

    // When they read the staff routes
    foreach (DASH_ENDPOINTS as $endpoint) {
        foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid'] as $id) {
            foreach ([[], ...dashBadQueries($endpoint)] as $query) {
                // Then it is the same 403 for every id and every query
                dashApi('staff', $id, $endpoint, $query)->assertForbidden();
            }
        }
    }
});

it('returns byte-identical payloads to the maker and to staff', function () {
    // Given a maker's product with data
    $maker = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'votes_count' => 2]);
    dashBucket($product, '2026-10-21 09:00:00', 2, 6);
    dashReferrer($product, DASH_DAY, 'google', 6);
    dashSnapshot($product, '2026-10-21 09:00:00', 1);
    $own = collect(DASH_ENDPOINTS)->map(fn ($e) => dashApi('me', $product->id, $e)->assertOk()->getContent())->all();

    // When staff read the same four
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    $staff = collect(DASH_ENDPOINTS)->map(fn ($e) => dashApi('staff', $product->id, $e)->assertOk()->getContent())->all();

    // Then they are identical
    expect($staff)->toBe($own);
});

it('has no staff or maker route for a product of a deleted maker beyond the uniform behaviour', function () {
    // Given a live product whose maker is deleted
    $maker = User::factory()->create(['deleted_at' => now()]);
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When staff read it
    // Then staff still can (support), and the deleted maker cannot authenticate at all
    dashApi('staff', $product->id)->assertOk();
});

it('throttles dashboard reads at 30 a minute per member across both prefixes', function () {
    // Given a reviewer, 15 reads on the staff prefix and 15 on the maker prefix (not the maker: 404, still counted)
    $product = dashProduct();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    foreach (range(1, 15) as $_) {
        dashApi('staff', $product->id)->assertOk();
        dashApi('me', $product->id)->assertNotFound();
    }

    // When the 31st arrives
    $response = dashApi('staff', $product->id);

    // Then it is 429 with Retry-After
    $response->assertStatus(429)->assertHeader('Retry-After');
});

it('carries the dashboard limiter and the three guards on all eight routes', function () {
    // Given the registered routes
    $routes = dashRouteMiddleware();

    // When each of the eight is inspected
    foreach (['me', 'staff'] as $prefix) {
        foreach (['', '/series', '/rank', '/referrers'] as $suffix) {
            $key = "GET {$prefix}/products/{product}/dashboard{$suffix}";
            expect($routes)->toHaveKey($key);

            // Then it has the guards and exactly the dashboard limiter above the api one
            expect($routes[$key])->toContain('auth:sanctum', 'active', 'verified.api');
            $named = collect($routes[$key])->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->values()->all();
            expect($named)->toBe(['throttle:dashboard']);
        }
    }
});

it('marks all eight responses no-store and private', function () {
    // Given a maker with a product, and a reviewer
    $maker = signedInMember();
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id]);

    // When each route is read (the maker's, then staff's)
    foreach (DASH_ENDPOINTS as $endpoint) {
        $response = dashApi('me', $product->id, $endpoint)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    foreach (DASH_ENDPOINTS as $endpoint) {
        $response = dashApi('staff', $product->id, $endpoint)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
});

it('runs a fixed small number of statements per endpoint and no N+1 across 5 versus 50 rows', function () {
    // Given a small and a large product, both launching today
    $makerA = signedInMember();
    $small = dashProduct(DASH_DAY, ['maker_id' => $makerA->id, 'hot_score' => 5.0]);
    $large = dashProduct(DASH_DAY, ['maker_id' => $makerA->id, 'hot_score' => 4.0]);
    foreach (range(0, 4) as $i) {
        dashBucket($small, sprintf('2026-10-21 %02d:00:00', $i), 1, 1);
        dashSnapshot($small, sprintf('2026-10-21 00:%02d:00', $i * 10), 3);
        dashReferrer($small, DASH_DAY, "s{$i}.example.com", 1);
    }
    foreach (range(0, 23) as $i) {
        dashBucket($large, sprintf('2026-10-21 %02d:00:00', $i), 1, 1);
    }
    foreach (range(0, 49) as $i) {
        dashSnapshot($large, sprintf('2026-10-21 %02d:%02d:00', intdiv($i, 6), ($i % 6) * 10), 3);
        dashReferrer($large, DASH_DAY, "s{$i}.example.com", 1);
    }
    $bounds = ['' => 9, 'series' => 4, 'rank' => 5, 'referrers' => 3];
    dashApi('me', $small->id)->assertOk(); // warms the settings cache, which is not part of a read

    foreach ($bounds as $endpoint => $bound) {
        // When the endpoint is read for both
        $a = count(dashAllQueries(fn () => dashApi('me', $small->id, $endpoint)->assertOk()));
        $b = count(dashAllQueries(fn () => dashApi('me', $large->id, $endpoint)->assertOk()));

        // Then the count is the same, and within the bound plus the product lookup
        expect($b)->toBe($a)->and($a)->toBeLessThanOrEqual($bound + 1);
    }
});
