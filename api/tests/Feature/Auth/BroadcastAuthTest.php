<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

const BROADCAST_AUTH = '/api/v1/broadcasting/auth';

function channelAuth(string $channel, string $socketId = '1234.5678'): array
{
    return ['socket_id' => $socketId, 'channel_name' => $channel];
}

it('authorises a member for their own private channel with a correctly signed response', function () {
    // Given a signed-in member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they ask to join their own channel
    $response = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then they receive exactly one key:signature token signed with the app secret
    $response->assertOk()->assertExactJsonStructure(['auth']);
    $expected = 'test-key:'.hash_hmac('sha256', '1234.5678:private-user.'.$user->id, 'test-secret');
    expect($response->json('auth'))->toBe($expected);
});

it('works with a bearer token', function () {
    // Given a member holding a mobile token
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When they ask to join their own channel
    $response = $this->withToken($token)->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then they are authorised
    $response->assertOk()->assertJsonStructure(['auth']);
});

it('works with a session cookie from the web app', function () {
    // Given a member signed in through the session guard on a stateful origin
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    // When the web app asks to join their own channel
    $response = spa()->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then they are authorised
    $response->assertOk()->assertJsonStructure(['auth']);
});

it('answers every foreign or unknown channel with the same 403', function (string $channel) {
    // Given a signed-in member and production-style errors (debug traces would differ by file and line)
    config(['app.debug' => false]);
    $user = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($user);
    $name = str_replace(['{self}', '{other}'], [$user->id, $other->id], $channel);

    // When they ask for a channel that is not theirs
    $response = $this->postJson(BROADCAST_AUTH, channelAuth($name));

    // Then it is a 403 with no hint about which part failed
    $response->assertForbidden();
    expect($response->getContent())->toBe($this->postJson(BROADCAST_AUTH, channelAuth('private-nope'))->getContent());
})->with([
    'another member' => 'private-user.{other}',
    'unknown id' => 'private-user.01JZZZZZZZZZZZZZZZZZZZZZZZ',
    'trailing character' => 'private-user.{self}-',
    'trailing dot' => 'private-user.{self}.',
    'empty id' => 'private-user.',
    'unknown channel' => 'private-orders.1',
    'presence channel' => 'presence-user.{self}',
    'encrypted channel' => 'private-encrypted-user.{self}',
    'no prefix' => 'user.{self}',
    'public product channel' => 'product.1',
    'case variant' => 'PRIVATE-user.{self}',
]);

it('answers 503 instead of an empty 200 when the broadcast driver cannot authorise', function (string $driver) {
    // Given a deployment whose driver is log or null (they approve every channel without checking)
    config(['broadcasting.default' => $driver]);
    $user = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($user);

    // When a member asks for another member's channel
    $response = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$other->id));

    // Then the endpoint fails closed
    $response->assertStatus(503);
})->with(['log', 'null']);

it('compares ids as strict strings', function () {
    // Given a member whose id is a lowercase ULID string
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When the channel id differs only by case
    $response = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.strtoupper($user->id)));

    // Then it is refused
    $response->assertForbidden();
});

it('refuses anonymous callers with a 401', function () {
    // Given a channel
    $user = User::factory()->create();

    // When an anonymous caller asks
    // Then it is a 401
    $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id))->assertUnauthorized();
});

it('gives suspended and deleted members no channel auth', function (string $state) {
    // Given a member in a blocked state holding a valid token
    $user = match ($state) {
        'suspended' => User::factory()->suspended()->create(),
        'deleted' => User::factory()->create(['deleted_at' => now()]),
    };
    $token = $user->createToken('phone')->plainTextToken;

    // When they ask to join their own channel
    $response = $this->withToken($token)->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then they are refused
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect($response->json())->not->toHaveKey('auth');
})->with(['suspended', 'deleted']);

it('authorises before validating: a suspended member with a bad payload gets 403, not 422', function () {
    // Given a suspended member holding a valid token
    $user = User::factory()->suspended()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When they send an invalid payload
    $response = $this->withToken($token)->postJson(BROADCAST_AUTH, ['channel_name' => ['x'], 'socket_id' => null]);

    // Then the account check answers first
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('refuses an expired token with a 401', function () {
    // Given a member whose token expired yesterday
    $user = User::factory()->create();
    $newToken = $user->createToken('phone');
    $newToken->accessToken->forceFill(['expires_at' => now()->subDay()])->save();

    // When they ask to join their own channel
    $response = $this->withToken($newToken->plainTextToken)->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then they are unauthenticated
    $response->assertUnauthorized();
});

it('lets an unverified member listen to their own channel', function () {
    // Given a member who has not verified their email
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    // When they ask to join their own channel
    $response = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then it is allowed: the channel only carries their own data, and verification prompts use it
    $response->assertOk();
});

it('rejects odd payloads with a 4xx and never a 500', function (mixed $channel, mixed $socketId) {
    // Given a signed-in member
    Sanctum::actingAs(User::factory()->create());

    // When the payload has the wrong shape
    $response = $this->postJson(BROADCAST_AUTH, ['channel_name' => $channel, 'socket_id' => $socketId]);

    // Then it is a 422
    $response->assertStatus(422);
})->with([
    'channel array' => [['x'], '1234.5678'],
    'channel object' => [['a' => 'b'], '1234.5678'],
    'channel empty array' => [[], '1234.5678'],
    'channel null' => [null, '1234.5678'],
    'channel number' => [123, '1234.5678'],
    'channel bool' => [true, '1234.5678'],
    'channel empty' => ['', '1234.5678'],
    'channel oversized' => [str_repeat('a', 5000), '1234.5678'],
    'socket array' => ['private-user.x', ['1.2']],
    'socket object' => ['private-user.x', ['a' => '1.2']],
    'socket null' => ['private-user.x', null],
    'socket number' => ['private-user.x', 12345],
    'socket empty' => ['private-user.x', ''],
    'socket oversized' => ['private-user.x', str_repeat('1', 5000).'.1'],
    'socket not pusher format' => ['private-user.x', 'abc'],
    'socket injection' => ['private-user.x', "1.2\n3.4"],
]);

it('rejects missing fields and a non-JSON body with a 4xx', function () {
    // Given a signed-in member
    Sanctum::actingAs(User::factory()->create());

    // When nothing, or only a query string, is sent
    // Then it is a 422 either way
    $this->postJson(BROADCAST_AUTH)->assertStatus(422);
    $this->post(BROADCAST_AUTH.'?channel_name[]=x&socket_id[]=y', [], ['Accept' => 'application/json'])->assertStatus(422);
});

it('throttles the endpoint per member', function () {
    // Given a member who used up the minute budget
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    foreach (range(1, 30) as $_) {
        $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id))->assertOk();
    }

    // When one more arrives, and when another member calls
    $extra = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));
    $other = User::factory()->create();
    Sanctum::actingAs($other);
    $fine = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$other->id));

    // Then the first is throttled with Retry-After and the second has their own bucket
    $extra->assertStatus(429)->assertHeader('Retry-After');
    $fine->assertOk();
});

it('registers exactly one broadcasting auth route behind sanctum, active and the limiter', function () {
    // Given the registered routes
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'broadcasting'));

    // When their middleware is read
    // Then there is one POST route with the expected guards
    expect($routes)->toHaveCount(1);
    $route = $routes->first();
    expect($route->uri())->toBe('api/v1/broadcasting/auth')
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toContain('auth:sanctum', 'active', 'throttle:broadcast-auth');
});

it('sends no-store on the auth response', function () {
    // Given a signed-in member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they get a signed token
    $response = $this->postJson(BROADCAST_AUTH, channelAuth('private-user.'.$user->id));

    // Then it must not be cached
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
