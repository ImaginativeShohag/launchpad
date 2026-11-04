<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\IssueAccessToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;

class IssueTokenController extends Controller
{
    public function __invoke(IssueTokenRequest $request, AuthenticateUser $authenticate, IssueAccessToken $issue): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = $authenticate->handle($email, $request->string('password')->toString());
        $token = $issue->handle($user, $request->string('device_name')->toString());
        LoginThrottle::clear($request, $email);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => (new UserResource($user->refresh()))->resolve($request),
            ],
        ], 201);
    }
}
