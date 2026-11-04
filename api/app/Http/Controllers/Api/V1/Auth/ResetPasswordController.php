<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\SignOutEverywhere;
use App\Exceptions\BlockedResetException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request, SignOutEverywhere $signOut): JsonResponse
    {
        $email = (string) User::normaliseEmail($request->validated('email'));

        // Always go through the broker, with no user lookup first: the broker pads every call to a fixed
        // time (Timebox), so an early exit for unknown or blocked accounts would reveal them by speed.
        // Eligibility is checked inside the callback, which only runs after the token has been proven.
        try {
            // One transaction: the new password, the revocations and the token deletion stand or fall together.
            $status = DB::transaction(fn () => PasswordBroker::reset([
                'email' => $email,
                'token' => $request->validated('token'),
                'password' => $request->validated('password'),
            ], function (User $found, string $password) use ($signOut): void {
                // Lock the row so two concurrent resets with the same token cannot both succeed.
                $user = User::query()->whereKey($found->getKey())->lockForUpdate()->first();

                if ($user === null || $user->isSuspended() || $user->isDeleted()) {
                    // Rolls back, so the token is not consumed.
                    throw new BlockedResetException;
                }

                $user->forceFill(['password' => $password])->save();
                $signOut->handle($user);
                event(new PasswordReset($user));
            }));
        } catch (BlockedResetException) {
            $status = PasswordBroker::INVALID_USER;
        }

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            // One message for a bad token, an expired token, an unknown email and a blocked account alike.
            throw ValidationException::withMessages(['email' => ['This reset link is invalid or has expired.']]);
        }

        return response()->json(['message' => 'Your password has been updated. Sign in with the new password.']);
    }
}
