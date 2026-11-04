<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, handle: string} $data */
        $data = $request->validated();
        $user = $register->handle($data);

        // Browsers get a session; mobile clients call POST /auth/token next.
        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
