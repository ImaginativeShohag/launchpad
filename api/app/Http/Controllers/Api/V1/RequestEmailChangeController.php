<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestEmailChangeRequest;
use App\Models\User;
use App\Support\PasswordCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RequestEmailChangeController extends Controller
{
    public function __invoke(RequestEmailChangeRequest $request, RequestEmailChange $change): JsonResponse
    {
        $user = $this->authenticated($request);

        if ($user->hasPassword() && ! PasswordCheck::verify($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $email = $request->validated('email');

        // Accepted trade-off (spec section 5): a plain 422 tells a signed-in member that an address is
        // registered. It is only reachable with the password (or a fresh sign-in) and 3 times an hour.
        // The member's own address gets the same plain 422.
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }

        $change->handle($user, $email);

        return response()->json(['message' => 'Check the new address for a confirmation link.'], 202);
    }
}
