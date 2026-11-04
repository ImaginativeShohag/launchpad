<?php

namespace App\Support\Google;

use App\Exceptions\GoogleUnavailable;
use Google\AccessToken\Verify;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Real Google integration. The two network calls sit in small protected methods so tests can replace
 * them and exercise the mapping rules: a usable identity needs a non-empty `sub` and a valid email,
 * and the email counts as verified only when Google says a real boolean `true`.
 * Nothing here logs or exposes tokens or Google's error text.
 */
class SocialiteGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function redirect(): RedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('google');

        /** @var RedirectResponse */
        return $driver->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function fromCallback(): GoogleIdentity
    {
        $user = $this->socialiteUser();

        return $this->identity($user->getId(), $user->getEmail(), $user->user['email_verified'] ?? null, $user->getName())
            ?? throw new RuntimeException('Google returned an unusable identity.');
    }

    /**
     * Signature, issuer and expiry are checked by Google's library against Google's published certificates
     * (fetched with timeouts and cached). The audience is checked here against every configured client id.
     * Returns null for any invalid token; throws GoogleUnavailable when Google cannot be reached.
     *
     * @throws GoogleUnavailable
     */
    public function fromIdToken(string $idToken): ?GoogleIdentity
    {
        $allowed = array_values(array_filter((array) config('services.google.mobile_client_ids'), 'is_string'));

        if ($allowed === []) {
            Log::warning('No Google mobile client id is configured; every Google ID token is refused.');

            return null;
        }

        $payload = $this->verifiedPayload($idToken);

        if (! is_array($payload)) {
            return null;
        }

        // Defence in depth: do not rely only on the library for these.
        $issuerOk = in_array($payload['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true);
        $audienceOk = in_array($payload['aud'] ?? null, $allowed, true);
        $exp = $payload['exp'] ?? null;
        $fresh = (is_int($exp) || is_float($exp)) && $exp > time();

        if (! $issuerOk || ! $audienceOk || ! $fresh) {
            return null;
        }

        return $this->identity($payload['sub'] ?? null, $payload['email'] ?? null, $payload['email_verified'] ?? null, $payload['name'] ?? null);
    }

    /**
     * @return array<string, mixed>|false
     *
     * @throws GoogleUnavailable
     */
    protected function verifiedPayload(string $idToken): array|false
    {
        try {
            return (new Verify($this->httpClient(), new LaravelCachePool($this->certCache())))->verifyIdToken($idToken);
        } catch (Throwable $e) {
            for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof ClientExceptionInterface) {
                    throw new GoogleUnavailable('Google certificates could not be fetched.');
                }
            }

            return false; // Malformed, unknown key id, bad signature, expired: all just "invalid".
        }
    }

    /** The shared cache; if even resolving it fails, fall back to a per-request array cache (certs are then fetched). */
    private function certCache(): CacheRepositoryContract
    {
        try {
            return Cache::store();
        } catch (Throwable $e) {
            report($e);

            return new CacheRepository(new ArrayStore);
        }
    }

    /** Short timeouts, and any non-2xx certificate response counts as "Google unavailable" (never as a bad token). */
    protected function httpClient(): GuzzleClient
    {
        $stack = HandlerStack::create($this->httpHandler());
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response): ResponseInterface {
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException('Google certificates returned HTTP '.$response->getStatusCode(), 0, new TransferException);
            }

            return $response;
        }));

        return new GuzzleClient(['handler' => $stack, 'timeout' => 5, 'connect_timeout' => 2]);
    }

    /** Transport seam for tests; null means Guzzle's default. */
    protected function httpHandler(): ?callable
    {
        return null;
    }

    protected function socialiteUser(): SocialiteUser
    {
        /** @var SocialiteUser */
        return Socialite::driver('google')->user();
    }

    private function identity(mixed $sub, mixed $email, mixed $verified, mixed $name): ?GoogleIdentity
    {
        $sub = is_string($sub) ? trim($sub) : '';
        $email = is_string($email) ? trim($email) : '';

        if ($sub === '' || strlen($sub) > 255 || $email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = is_string($name) && trim($name) !== '' ? trim($name) : Str::before($email, '@');

        return new GoogleIdentity($sub, $email, $verified === true, $name);
    }
}
