<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $email = (string) User::normaliseEmail($request->validated('email'));

        // No lookup, hashing or audit write here: creating a reset token costs a password hash, so doing
        // it only for registered emails would be a timing oracle. The job does it all off the request
        // path. Production must therefore not run QUEUE_CONNECTION=sync.
        SendPasswordResetLink::dispatch($email);

        // Always the same answer, so the endpoint cannot be used to find registered emails.
        return response()->json(['message' => 'If that email has an account, a reset link is on its way.'], 202);
    }
}
