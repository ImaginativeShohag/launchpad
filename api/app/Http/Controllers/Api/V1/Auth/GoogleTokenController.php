<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssueAccessToken;
use App\Actions\Auth\LoginWithGoogle;
use App\Exceptions\AccountUnavailable;
use App\Exceptions\GoogleEmailNotVerified;
use App\Exceptions\GoogleIdentityMismatch;
use App\Exceptions\GoogleUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleTokenRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Support\Google\GoogleIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class GoogleTokenController extends Controller
{
    public function __invoke(
        GoogleTokenRequest $request,
        GoogleIdentityProvider $google,
        LoginWithGoogle $login,
        IssueAccessToken $issue,
    ): JsonResponse {
        try {
            $identity = $google->fromIdToken($request->string('id_token')->toString());
        } catch (GoogleUnavailable) {
            return response()->json([
                'message' => 'Google could not be reached. Please try again shortly.',
                'code' => 'google_unavailable',
            ], 503);
        }

        if ($identity === null) {
            throw ValidationException::withMessages(['id_token' => ['The Google token is invalid.']]);
        }

        try {
            $user = $login->handle($identity);
        } catch (GoogleEmailNotVerified) {
            return response()->json([
                'message' => 'Your Google email address is not verified.',
                'code' => 'google_email_unverified',
            ], 409);
        } catch (GoogleIdentityMismatch) {
            return response()->json([
                'message' => 'This email address is already linked to a different Google account.',
                'code' => 'google_identity_mismatch',
            ], 409);
        } catch (AccountUnavailable) {
            return response()->json([
                'message' => 'This account is unavailable.',
                'code' => 'account_unavailable',
            ], 403);
        }

        $token = $issue->handle($user, $request->string('device_name')->toString());

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => (new UserResource($user->refresh()))->resolve($request),
            ],
        ], 201);
    }
}
