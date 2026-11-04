<?php

use App\Exceptions\GoogleUnavailable;
use App\Support\Google\SocialiteGoogleIdentityProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Provider whose Google calls are replaced by canned data. */
function providerWithPayload(array|false $payload, ?SocialiteUser $user = null): SocialiteGoogleIdentityProvider
{
    // A token that passed Google's signature check; audience, issuer and expiry are re-checked by the provider.
    if (is_array($payload)) {
        $payload += ['aud' => 'ios-client', 'iss' => 'https://accounts.google.com', 'exp' => time() + 600];
    }

    return new class($payload, $user) extends SocialiteGoogleIdentityProvider
    {
        public function __construct(private array|false $payload, private ?SocialiteUser $user) {}

        protected function verifiedPayload(string $idToken): array|false
        {
            return $this->payload;
        }

        protected function socialiteUser(): SocialiteUser
        {
            return $this->user ?? throw new RuntimeException('no user');
        }
    };
}

function socialiteUser(array $raw, mixed $id = 'g-1', mixed $email = 'amina@example.com', mixed $name = 'Amina'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->map(['id' => $id, 'email' => $email, 'name' => $name]);
    $user->user = $raw;

    return $user;
}

beforeEach(fn () => config(['services.google.mobile_client_ids' => ['ios-client', 'android-client']]));

it('maps a valid ID token payload to an identity', function () {
    // Given Google's verifier accepts the token
    $provider = providerWithPayload(['sub' => '123', 'email' => 'Amina@Example.com', 'email_verified' => true, 'name' => 'Amina Rahman']);

    // When it is mapped
    $identity = $provider->fromIdToken('t');

    // Then the identity carries the claims
    expect($identity)->not->toBeNull()
        ->and($identity->sub)->toBe('123')
        ->and($identity->email)->toBe('Amina@Example.com')
        ->and($identity->emailVerified)->toBeTrue()
        ->and($identity->name)->toBe('Amina Rahman');
});

it('returns null when the verifier rejects the token', function () {
    // Given a verifier that rejects the token
    // When it is verified
    // Then there is no identity
    expect(providerWithPayload(false)->fromIdToken('t'))->toBeNull();
});

it('lets an outage from the verifier reach the caller', function () {
    // Given a verifier that cannot reach Google
    $provider = new class extends SocialiteGoogleIdentityProvider
    {
        protected function verifiedPayload(string $idToken): array|false
        {
            throw new GoogleUnavailable;
        }
    };

    // When / Then it is not hidden as an invalid token
    expect(fn () => $provider->fromIdToken('t'))->toThrow(GoogleUnavailable::class);
});

it('rejects payloads with a missing or malformed sub or email', function (array $payload) {
    // Given a verified-looking payload with a bad claim
    // When it is mapped
    // Then no identity is produced
    expect(providerWithPayload($payload)->fromIdToken('t'))->toBeNull();
})->with([
    'no sub' => [['email' => 'a@example.com', 'email_verified' => true]],
    'empty sub' => [['sub' => '', 'email' => 'a@example.com']],
    'array sub' => [['sub' => ['x'], 'email' => 'a@example.com']],
    'int sub' => [['sub' => 5, 'email' => 'a@example.com']],
    'no email' => [['sub' => '1', 'email_verified' => true]],
    'empty email' => [['sub' => '1', 'email' => '']],
    'blank email' => [['sub' => '1', 'email' => '   ']],
    'array email' => [['sub' => '1', 'email' => ['a@example.com']]],
    'not an email' => [['sub' => '1', 'email' => 'nope']],
    'too long email' => [['sub' => '1', 'email' => str_repeat('a', 250).'@example.com']],
]);

it('counts anything but a real boolean true as an unverified email', function (mixed $claim) {
    // Given a payload whose email_verified claim is not a boolean true
    $payload = ['sub' => '1', 'email' => 'a@example.com', 'email_verified' => $claim];

    // When it is mapped
    $identity = providerWithPayload($payload)->fromIdToken('t');

    // Then the email is unverified
    expect($identity->emailVerified)->toBeFalse();
})->with(['string true' => ['true'], 'one' => [1], 'string yes' => ['yes'], 'false' => [false], 'null' => [null], 'array' => [[true]]]);

it('uses the email name when the token has no usable name', function (mixed $name) {
    // Given a payload with a missing or odd name
    $payload = ['sub' => '1', 'email' => 'rafi.khan@example.com', 'email_verified' => true, 'name' => $name];

    // When it is mapped
    $identity = providerWithPayload($payload)->fromIdToken('t');

    // Then the local part of the email is the name
    expect($identity->name)->toBe('rafi.khan');
})->with(['null' => [null], 'array' => [['x']], 'blank' => ['  ']]);

it('maps a Socialite callback user to an identity', function () {
    // Given Socialite returned a Google user with a verified email
    $provider = providerWithPayload(false, socialiteUser(['email_verified' => true]));

    // When the callback is read
    $identity = $provider->fromCallback();

    // Then the identity is complete
    expect($identity->sub)->toBe('g-1')->and($identity->email)->toBe('amina@example.com')
        ->and($identity->emailVerified)->toBeTrue()->and($identity->name)->toBe('Amina');
});

it('fails the callback for a missing sub or email and never invents an empty email', function (mixed $id, mixed $email) {
    // Given Socialite returned incomplete data
    $provider = providerWithPayload(false, socialiteUser(['email_verified' => true], $id, $email));

    // When / Then the callback is a failure
    expect(fn () => $provider->fromCallback())->toThrow(RuntimeException::class);
})->with([
    'no id' => [null, 'a@example.com'],
    'empty id' => ['', 'a@example.com'],
    'no email' => ['1', null],
    'empty email' => ['1', ''],
    'not an email' => ['1', 'nope'],
]);

it('counts a non-boolean email_verified on the callback as unverified', function (mixed $claim) {
    // Given Socialite's raw payload with a loose claim
    $provider = providerWithPayload(false, socialiteUser(['email_verified' => $claim]));

    // When the callback is read
    // Then it is unverified
    expect($provider->fromCallback()->emailVerified)->toBeFalse();
})->with(['string true' => ['true'], 'one' => [1], 'missing' => [null]]);
