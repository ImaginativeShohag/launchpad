<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Support\LoginThrottle;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUser $authenticate): UserResource
    {
        abort_unless($request->hasSession(), 400, 'Browser sessions only. Apps must use POST /api/v1/auth/token.');

        $email = $request->string('email')->toString();
        $user = $authenticate->handle($email, $request->string('password')->toString());

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        LoginThrottle::clear($request, $email);

        return new UserResource($user->refresh());
    }
}
