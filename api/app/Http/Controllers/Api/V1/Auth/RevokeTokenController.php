<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AuthEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class RevokeTokenController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var PersonalAccessToken|TransientToken|null $token */
        $token = $user->currentAccessToken();
        abort_if($token === null || $token instanceof TransientToken, 400, 'This request was not made with an access token.');

        $token->delete();
        AuthLog::record(AuthEventType::TokenRevoked, $user->getKey());

        return response()->noContent();
    }
}
