<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LoginWithGoogle;
use App\Exceptions\AccountUnavailable;
use App\Exceptions\GoogleEmailNotVerified;
use App\Exceptions\GoogleIdentityMismatch;
use App\Http\Controllers\Controller;
use App\Support\Google\GoogleIdentityProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleIdentityProvider $google, LoginWithGoogle $login): RedirectResponse
    {
        try {
            $identity = $google->fromCallback();
        } catch (Throwable $e) {
            // Class name only: Google/Guzzle messages can embed codes or tokens.
            Log::warning('Google callback failed.', ['exception' => $e::class]);

            return $this->failed('google_failed');
        }

        try {
            $user = $login->handle($identity);
        } catch (GoogleEmailNotVerified) {
            return $this->failed('google_email_unverified');
        } catch (GoogleIdentityMismatch) {
            return $this->failed('google_identity_mismatch');
        } catch (AccountUnavailable) {
            return $this->failed('account_unavailable');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect((string) config('launchpad.web_url').'/');
    }

    /** The redirect carries a fixed error code only, never user data or exception text. */
    private function failed(string $code): RedirectResponse
    {
        return redirect((string) config('launchpad.web_url').'/signin?error='.$code);
    }
}
