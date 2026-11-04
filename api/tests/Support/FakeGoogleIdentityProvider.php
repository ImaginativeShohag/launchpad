<?php

namespace Tests\Support;

use App\Support\Google\GoogleIdentity;
use App\Support\Google\GoogleIdentityProvider;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class FakeGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function __construct(private readonly ?GoogleIdentity $identity) {}

    public function redirect(): RedirectResponse
    {
        return redirect('https://accounts.google.test/auth');
    }

    public function fromCallback(): GoogleIdentity
    {
        return $this->identity ?? throw new RuntimeException('Google rejected the callback.');
    }

    public function fromIdToken(string $idToken): ?GoogleIdentity
    {
        return $idToken === 'bad-token' ? null : $this->identity;
    }
}
