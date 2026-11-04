<?php

use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/** The ten anti-cheat staff routes: method and path (without api/v1/), in the order the application registers them is irrelevant. */
const ANTI_CHEAT_ROUTES = [
    'GET staff/risk-signals', 'GET staff/risk-signals/{signal}', 'POST staff/risk-signals/{signal}/review',
    'GET staff/risk/members', 'GET staff/risk/members/{member}', 'POST staff/risk/members/{member}/void-votes', 'POST staff/risk/members/{member}/suspend',
    'GET staff/products/{product}/vote-integrity', 'GET staff/products/{product}/votes', 'POST staff/votes/{vote}/void',
];

const ANTI_CHEAT_WRITES = ['POST staff/risk-signals/{signal}/review', 'POST staff/risk/members/{member}/void-votes', 'POST staff/risk/members/{member}/suspend', 'POST staff/votes/{vote}/void'];

beforeEach(fn () => antiCheatFreeze());

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware, for the anti-cheat routes */
function antiCheatRoutesFound(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $path = substr($route->uri(), strlen('api/v1/'));

        if (! str_starts_with($route->uri(), 'api/v1/') || preg_match('~^staff/(risk|votes)|^staff/products/\{[^}]+\}/(vote-integrity|votes)$~', $path) !== 1) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                $found[$method.' '.$path] = $route->gatherMiddleware();
            }
        }
    }

    ksort($found);

    return $found;
}

/** The ten calls for an id: [method, path, body]. */
function antiCheatRoutesCalls(string $id): array
{
    return [
        ['GET', "risk-signals/{$id}", []], ['GET', 'risk-signals', []], ['POST', "risk-signals/{$id}/review", ['outcome' => 'confirmed']],
        ['GET', 'risk/members', []], ['GET', "risk/members/{$id}", []],
        ['POST', "risk/members/{$id}/void-votes", ['reason' => 'Ring votes here']], ['POST', "risk/members/{$id}/suspend", ['reason' => 'Ring of fakes', 'void_votes' => false]],
        ['GET', "products/{$id}/vote-integrity", []], ['GET', "products/{$id}/votes", []], ['POST', "votes/{$id}/void", ['reason' => 'Ring vote here']],
    ];
}

function antiCheatRoutesSend(array $call): TestResponse
{
    [$method, $path, $body] = $call;

    return $method === 'GET' ? test()->getJson('/api/v1/staff/'.$path) : test()->postJson('/api/v1/staff/'.$path, $body);
}

it('has exactly the ten anti-cheat staff routes: nothing that un-voids, deletes a signal or lists voters outside staff', function () {
    // Given every registered route under staff/risk, staff/votes or the product vote reads
    // When they are listed
    $found = array_keys(antiCheatRoutesFound());

    // Then they are the documented ten
    $expected = ANTI_CHEAT_ROUTES;
    sort($expected);
    expect($found)->toBe($expected);

    // And no other route anywhere mentions risk, signals, void or suspend outside the documented set and the community admin suspension
    $others = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && preg_match('~risk|signal|unvote|un-vote|void|voter~i', $r->uri()) === 1)
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->map(fn ($m) => $m.' '.substr($r->uri(), 7)))->sort()->values()->all();
    expect($others)->toBe(collect(ANTI_CHEAT_ROUTES)->reject(fn ($r) => str_contains($r, 'vote-integrity') || str_ends_with($r, '/votes'))->sort()->values()->all());
});

it('puts sign-in, an active account and a confirmed email on every route, and exactly the admin-write limiter on the four writes, below the api bucket', function () {
    // Given the routes
    $routes = antiCheatRoutesFound();

    // When their middleware is inspected
    // Then every one carries the three guards, the writes exactly one named limiter and the reads none
    foreach ($routes as $key => $middleware) {
        expect($middleware)->toContain('auth:sanctum', 'active', 'verified.api');
        $named = collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:api')->values()->all();
        expect($named)->toBe(in_array($key, ANTI_CHEAT_WRITES, true) ? ['throttle:admin-write'] : [], $key);
    }

    // And the limiter's minute bucket (30) is below the group's 60, and its hour bucket exists
    $user = User::factory()->create();
    $request = tap(Request::create('/x', 'POST'), fn (Request $r) => $r->setUserResolver(fn () => $user));
    $limits = collect(Arr::wrap(RateLimiter::limiter('admin-write')($request)));
    $minute = $limits->first(fn ($limit) => $limit->decaySeconds === 60);
    $group = collect(Arr::wrap(RateLimiter::limiter('api')($request)))->first(fn ($limit) => $limit->decaySeconds === 60);
    expect($minute->maxAttempts)->toBe(30)->and($minute->maxAttempts)->toBeLessThan($group->maxAttempts)->and($limits->first(fn ($limit) => $limit->decaySeconds === 3600)->maxAttempts)->toBe(200);
});

it('marks every response of these routes no-store and private, for success, refusal and missing ids alike', function () {
    // Given an admin
    signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When each route is called with an existing product id, an unknown id and a body that fails validation
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid'] as $id) {
        foreach (antiCheatRoutesCalls($id) as $call) {
            // Then the Cache-Control header forbids caching whatever the status
            $response = antiCheatRoutesSend($call);
            expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        }
    }
});

it('answers a suspended or unverified staff member the same guard 403 on all ten routes for an existing and a missing id', function (string $state, string $code) {
    // Given a suspended or unverified admin
    $staff = signedInStaff(Role::Admin);
    $state === 'suspended' ? $staff->forceFill(['suspended_at' => now()])->save() : $staff->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create();

    // When each route is called with an existing and a missing id
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatRoutesCalls($id) as $call) {
            // Then the guard answers, never a 404 or a 422
            antiCheatRoutesSend($call)->assertForbidden()->assertJsonPath('code', $code);
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a guest 401 on all ten routes', function () {
    // Given no sign-in and a product
    $product = Product::factory()->live()->create();

    // When each route is called
    // Then 401 each, for an existing and a missing id
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatRoutesCalls($id) as $call) {
            antiCheatRoutesSend($call)->assertUnauthorized();
        }
    }
});

it('answers a member 403 on all ten routes, identical for existing and unknown ids and for invalid input', function () {
    // Given a member, a product and a signal
    signedInMember();
    $product = Product::factory()->live()->create();

    // When each route is called with an existing id, an unknown id and a garbage body
    $bodies = [];
    foreach (antiCheatRoutesCalls($product->id) as $i => $call) {
        $bodies[$i][] = antiCheatRoutesSend($call)->assertForbidden()->json('message');
        $bodies[$i][] = antiCheatRoutesSend([$call[0], str_replace($product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', $call[1]), $call[2]])->assertForbidden()->json('message');
        $bodies[$i][] = antiCheatRoutesSend([$call[0], str_replace($product->id, 'nope', $call[1]).($call[0] === 'GET' ? '?cursor=!!!&per_page[]=1' : ''), $call[0] === 'GET' ? [] : ['reason' => ['x'], 'outcome' => ['y']]])->assertForbidden()->json('message');
    }

    // Then the three answers of a route are one and the same message (the debug trace of a local run is not part of the contract)
    foreach ($bodies as $same) {
        expect(array_unique($same))->toHaveCount(1);
    }
});

it('lets a reviewer read and review but refuses the three admin writes with 403 before any validation or lookup', function () {
    // Given a reviewer, a signal about another member, a product and a vote
    signedInStaff(Role::Reviewer);
    $member = antiCheatSetScore(User::factory()->create(), 80);
    $product = antiCheatLiveProduct();
    $vote = antiCheatCountedVote($member, $product);
    $signal = RiskSignal::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);

    // When the reads and the review are called, then the admin writes with good bodies, garbage bodies and unknown ids
    foreach ([['GET', 'risk-signals', []], ['GET', "risk-signals/{$signal->id}", []], ['GET', 'risk/members', []], ['GET', "risk/members/{$member->id}", []], ['GET', "products/{$product->id}/vote-integrity", []], ['GET', "products/{$product->id}/votes", []]] as $call) {
        antiCheatRoutesSend($call)->assertOk();
    }
    antiCheatRoutesSend(['POST', "risk-signals/{$signal->id}/review", ['outcome' => 'dismissed']])->assertOk();
    foreach ([$vote->id, $member->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'nope'] as $id) {
        foreach ([['POST', "votes/{$id}/void", ['reason' => 'Ring vote here']], ['POST', "risk/members/{$id}/void-votes", ['reason' => 'x']], ['POST', "risk/members/{$id}/suspend", ['reason' => ['x']]], ['POST', "risk/members/{$id}/suspend", []]] as $call) {
            // Then every admin write is the one 403
            antiCheatRoutesSend($call)->assertForbidden();
        }
    }
    expect(DB::table('votes')->where('id', $vote->id)->value('status'))->toBe('counted')->and(DB::table('users')->where('id', $member->id)->value('suspended_at'))->toBeNull();
});

it('lets an admin through on all ten routes', function () {
    // Given an admin, a member with a vote and a signal, and a second member to suspend
    signedInStaff(Role::Admin);
    $member = antiCheatSetScore(User::factory()->create(), 80);
    $product = antiCheatLiveProduct();
    $vote = antiCheatCountedVote($member, $product);
    $other = antiCheatCountedVote(User::factory()->create(), antiCheatLiveProduct());
    $signal = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 1);

    // When each route is called with real ids
    $calls = [
        ['GET', 'risk-signals', []], ['GET', "risk-signals/{$signal->id}", []], ['POST', "risk-signals/{$signal->id}/review", ['outcome' => 'confirmed']],
        ['GET', 'risk/members', []], ['GET', "risk/members/{$member->id}", []], ['POST', "risk/members/{$member->id}/void-votes", ['reason' => 'Ring votes here']],
        ['GET', "products/{$product->id}/vote-integrity", []], ['GET', "products/{$product->id}/votes", []], ['POST', "votes/{$other->id}/void", ['reason' => 'Ring vote here']],
        ['POST', "risk/members/{$member->id}/suspend", ['reason' => 'Ring of fakes', 'void_votes' => false]],
    ];

    // Then all answer 200
    foreach ($calls as $call) {
        antiCheatRoutesSend($call)->assertOk();
    }
    expect(DB::table('votes')->where('id', $vote->id)->value('status'))->toBe('voided');
});

it('schedules the detection job once, every five minutes, behind a ten minute lock on one server, as the 14th event', function () {
    // Given the schedule
    $events = collect(app(Schedule::class)->events());

    // When the detection entries are found
    $detect = $events->filter(fn ($e) => str_contains((string) $e->command, 'launchpad:detect-abuse'));

    // Then there is exactly one, and the total is the documented fourteen
    expect($detect)->toHaveCount(1)->and($events)->toHaveCount(14);
    $event = $detect->first();
    expect($event->expression)->toBe('*/5 * * * *')->and($event->expiresAt)->toBe(10)->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
