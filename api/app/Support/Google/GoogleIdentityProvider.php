<?php

namespace App\Support\Google;

use Illuminate\Http\RedirectResponse;

/** The only place that talks to Google, so tests can replace it. */
interface GoogleIdentityProvider
{
    /** Start the browser (redirect) flow. */
    public function redirect(): RedirectResponse;

    /** Finish the browser flow. Throws if Google rejects the callback or sends an unusable identity. */
    public function fromCallback(): GoogleIdentity;

    /** Verify a mobile ID token (signature, audience, expiry). Null when invalid. */
    public function fromIdToken(string $idToken): ?GoogleIdentity;
}
