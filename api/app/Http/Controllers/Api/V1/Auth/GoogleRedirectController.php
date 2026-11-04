<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\Google\GoogleIdentityProvider;
use Illuminate\Http\RedirectResponse;

class GoogleRedirectController extends Controller
{
    public function __invoke(GoogleIdentityProvider $google): RedirectResponse
    {
        return $google->redirect();
    }
}
