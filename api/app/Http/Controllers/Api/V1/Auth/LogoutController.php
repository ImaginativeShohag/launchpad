<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Token-authenticated requests have no session; there is nothing to end (use DELETE /auth/token).
        if ($request->hasSession()) {
            // Fires the Logout event, which RecordAuthEvent turns into a `logout` row.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
