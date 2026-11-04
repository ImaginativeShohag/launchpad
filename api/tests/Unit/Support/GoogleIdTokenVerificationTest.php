<?php

use App\Exceptions\GoogleUnavailable;
use App\Support\Google\LaravelCachePool;
use App\Support\Google\SocialiteGoogleIdentityProvider;
use Firebase\JWT\JWT;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Real verification path against locally generated keys; no network. */
function rsaKey(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return [$private, ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'k'.bin2hex(random_bytes(4)), 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]];
}

function verifierFor(MockHandler $mock): SocialiteGoogleIdentityProvider
{
    return new class($mock) extends SocialiteGoogleIdentityProvider
    {
        public function __construct(private MockHandler $mock) {}

        protected function httpHandler(): callable
        {
            return $this->mock;
        }
    };
}

function certs(array $jwk): Response
{
    return new Response(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=3600'], json_encode(['keys' => [$jwk]]));
}

function claims(array $override = []): array
{
    return $override + [
        'iss' => 'https://accounts.google.com', 'aud' => 'ios-client', 'sub' => '1234',
        'email' => 'amina@example.com', 'email_verified' => true, 'name' => 'Amina',
        'iat' => time() - 10, 'exp' => time() + 600,
    ];
}

beforeEach(function () {
    Cache::flush();
    config(['services.google.mobile_client_ids' => ['ios-client', 'android-client']]);
});

it('accepts a correctly signed token for an allowed audience', function () {
    // Given Google serves our public key
    [$private, $jwk] = rsaKey();
    $mock = new MockHandler([certs($jwk)]);

    // When a token signed with the private key is verified
    $identity = verifierFor($mock)->fromIdToken(JWT::encode(claims(), $private, 'RS256', $jwk['kid']));

    // Then the identity is returned
    expect($identity)->not->toBeNull()->and($identity->sub)->toBe('1234')->and($identity->emailVerified)->toBeTrue();
});

it('accepts the second configured client id and the bare issuer', function () {
    // Given a token for the android client from accounts.google.com
    [$private, $jwk] = rsaKey();

    // When it is verified
    $token = JWT::encode(claims(['aud' => 'android-client', 'iss' => 'accounts.google.com']), $private, 'RS256', $jwk['kid']);

    // Then it is accepted
    expect(verifierFor(new MockHandler([certs($jwk)]))->fromIdToken($token))->not->toBeNull();
});

it('reuses the cached certificates instead of fetching them for every sign-in', function () {
    // Given a single queued certificate response
    [$private, $jwk] = rsaKey();
    $mock = new MockHandler([certs($jwk)]);
    $token = JWT::encode(claims(), $private, 'RS256', $jwk['kid']);

    // When two tokens are verified
    $first = verifierFor($mock)->fromIdToken($token);
    $second = verifierFor($mock)->fromIdToken($token);

    // Then the second used the shared cache (a second fetch would have thrown from the empty queue)
    expect($first)->not->toBeNull()->and($second)->not->toBeNull();
});

it('rejects tokens with a wrong audience, issuer, expiry or signature', function (string $case) {
    // Given a token that is wrong in one way
    [$private, $jwk] = rsaKey();
    [$otherPrivate] = rsaKey();
    $token = match ($case) {
        'audience' => JWT::encode(claims(['aud' => 'someone-else']), $private, 'RS256', $jwk['kid']),
        'issuer' => JWT::encode(claims(['iss' => 'https://evil.example']), $private, 'RS256', $jwk['kid']),
        'expired' => JWT::encode(claims(['iat' => time() - 7200, 'exp' => time() - 3600]), $private, 'RS256', $jwk['kid']),
        'wrong key' => JWT::encode(claims(), $otherPrivate, 'RS256', $jwk['kid']),
        'tampered' => (function () use ($private, $jwk) {
            [$h, $p, $sig] = explode('.', JWT::encode(claims(), $private, 'RS256', $jwk['kid']));
            $evil = rtrim(strtr(base64_encode(json_encode(claims(['email' => 'victim@example.com']))), '+/', '-_'), '=');

            return "$h.$evil.$sig";
        })(),
        'garbage' => 'not.a.jwt',
    };

    // When it is verified
    $identity = verifierFor(new MockHandler([certs($jwk)]))->fromIdToken($token);

    // Then it is simply invalid
    expect($identity)->toBeNull();
})->with(['audience', 'issuer', 'expired', 'wrong key', 'tampered', 'garbage']);

it('reports Google as unavailable when the certificates cannot be fetched', function () {
    // Given a transport failure
    [$private, $jwk] = rsaKey();
    $mock = new MockHandler([new ConnectException('down', new Request('GET', 'https://www.googleapis.com'))]);

    // When a token is verified
    // Then that is an outage, not an invalid token
    expect(fn () => verifierFor($mock)->fromIdToken(JWT::encode(claims(), $private, 'RS256', $jwk['kid'])))
        ->toThrow(GoogleUnavailable::class);
});

it('reports Google as unavailable on a certificate server error', function () {
    // Given Google answers 503
    [$private, $jwk] = rsaKey();
    $mock = new MockHandler([new Response(503)]);

    // When / Then
    expect(fn () => verifierFor($mock)->fromIdToken(JWT::encode(claims(), $private, 'RS256', $jwk['kid'])))
        ->toThrow(GoogleUnavailable::class);
});

it('fails closed and warns when no mobile client id is configured', function () {
    // Given an empty allow-list
    config(['services.google.mobile_client_ids' => []]);
    [$private, $jwk] = rsaKey();
    Log::spy();

    // When a perfectly valid token is verified
    $identity = verifierFor(new MockHandler([certs($jwk)]))->fromIdToken(JWT::encode(claims(), $private, 'RS256', $jwk['kid']));

    // Then it is refused and the misconfiguration is logged without the token
    expect($identity)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), 'eyJ'));
});

it('stores, expires and deletes items in the PSR-6 pool over the Laravel cache', function () {
    // Given the pool
    $pool = new LaravelCachePool(Cache::store());

    // When an item is saved with a ttl
    $item = $pool->getItem('certs.key');
    expect($item->isHit())->toBeFalse();
    $pool->save($item->set(['a' => 1])->expiresAfter(60));

    // Then it is a hit with the same value, and it can be deleted
    expect($pool->hasItem('certs.key'))->toBeTrue();
    expect($pool->getItem('certs.key')->get())->toBe(['a' => 1]);
    $pool->deleteItem('certs.key');
    expect($pool->getItem('certs.key')->isHit())->toBeFalse();
});

/** Throws on every call, like a Redis outage. */
function brokenCache(): Repository
{
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get', 'put', 'forget')->andThrow(new RuntimeException('redis down'));

    return $store;
}

it('verifies tokens and maps outages normally when the cache store is down', function () {
    // Given a cache that throws on every call and certificates served by Google
    Cache::shouldReceive('store')->andReturn(brokenCache());
    [$private, $jwk] = rsaKey();
    $token = JWT::encode(claims(), $private, 'RS256', $jwk['kid']);

    // When a valid token is verified
    $identity = verifierFor(new MockHandler([certs($jwk)]))->fromIdToken($token);

    // Then the cache failure behaves like a miss and the sign-in still works
    expect($identity)->not->toBeNull();

    // And a transport failure still means "Google unavailable"
    $down = new MockHandler([new ConnectException('down', new Request('GET', 'https://www.googleapis.com'))]);
    expect(fn () => verifierFor($down)->fromIdToken($token))->toThrow(GoogleUnavailable::class);
});

it('treats corrupt or unexpected cached values as a miss', function (string $payload) {
    // Given garbage planted under the pool's key
    $pool = new LaravelCachePool(Cache::store());
    $key = 'psr6:'.sha1('certs.key');
    Cache::put($key, $payload, 60);

    // When the item is read
    $item = $pool->getItem('certs.key');

    // Then it is a miss, without any exception
    expect($item->isHit())->toBeFalse();
})->with([
    'garbage' => ['not serialized at all'],
    'foreign object' => [serialize(new ArrayObject(['x']))],
    'wrong shape' => [serialize(['other' => 1])],
    'scalar' => [serialize('string')],
]);

it('rejects forged tokens', function (string $case) {
    // Given a JWKS with our key, and a forged token
    [$private, $jwk] = rsaKey();
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $forge = function (array $header, Closure $sign) use ($b64, $jwk) {
        $signing = $b64(json_encode($header + ['typ' => 'JWT', 'kid' => $jwk['kid']])).'.'.$b64(json_encode(claims()));

        return $signing.'.'.$b64($sign($signing));
    };
    $modulusBytes = base64_decode(strtr($jwk['n'], '-_', '+/'));

    $token = match ($case) {
        'alg none' => $forge(['alg' => 'none'], fn () => ''),
        'HS256 with modulus string' => $forge(['alg' => 'HS256'], fn ($m) => hash_hmac('sha256', $m, $jwk['n'], true)),
        'HS256 with modulus bytes' => $forge(['alg' => 'HS256'], fn ($m) => hash_hmac('sha256', $m, $modulusBytes, true)),
        'HS256 with secret' => $forge(['alg' => 'HS256'], fn ($m) => hash_hmac('sha256', $m, 'secret', true)),
        'RS512 on RS256 key' => $forge(['alg' => 'RS512'], function ($m) use ($private) {
            openssl_sign($m, $signature, $private, OPENSSL_ALGO_SHA512);

            return $signature;
        }),
    };

    // When it is verified
    $identity = verifierFor(new MockHandler([certs($jwk)]))->fromIdToken($token);

    // Then it is refused
    expect($identity)->toBeNull();
})->with(['alg none', 'HS256 with modulus string', 'HS256 with modulus bytes', 'HS256 with secret', 'RS512 on RS256 key']);

it('refetches the certificates once for an unknown key id and accepts a rotated key', function () {
    // Given a warm cache holding key one
    [$private1, $jwk1] = rsaKey();
    [$private2, $jwk2] = rsaKey();
    verifierFor(new MockHandler([certs($jwk1)]))->fromIdToken(JWT::encode(claims(), $private1, 'RS256', $jwk1['kid']));

    // When Google rotates and a token signed with key two arrives (the refreshed set has both keys)
    $mock = new MockHandler([new Response(200, [], json_encode(['keys' => [$jwk1, $jwk2]]))]);
    $identity = verifierFor($mock)->fromIdToken(JWT::encode(claims(), $private2, 'RS256', $jwk2['kid']));

    // Then it is accepted after exactly one refetch
    expect($identity)->not->toBeNull()->and($mock->count())->toBe(0);
});

it('rejects an unknown key id that is still unknown after the refetch', function () {
    // Given a warm cache holding key one, and a token signed by a key Google never published
    [$private1, $jwk1] = rsaKey();
    [$rogue, $rogueJwk] = rsaKey();
    verifierFor(new MockHandler([certs($jwk1)]))->fromIdToken(JWT::encode(claims(), $private1, 'RS256', $jwk1['kid']));

    // When it is verified (the refreshed set still lacks that kid)
    $mock = new MockHandler([certs($jwk1)]);
    $identity = verifierFor($mock)->fromIdToken(JWT::encode(claims(), $rogue, 'RS256', $rogueJwk['kid']));

    // Then it is refused after one refetch
    expect($identity)->toBeNull()->and($mock->count())->toBe(0);
});
