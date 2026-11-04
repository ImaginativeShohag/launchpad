<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\SignOutEverywhere;
use App\Enums\AuthEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Support\AuthLog;
use App\Support\PasswordCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class UpdatePasswordController extends Controller
{
    public function __invoke(UpdatePasswordRequest $request, SignOutEverywhere $signOut): Response|JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if (! $user->hasPassword()) {
            return response()->json([
                'message' => 'This account has no password. Use "Forgot password" to set one.',
                'code' => 'no_password',
            ], 403);
        }

        if (! PasswordCheck::verify($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        /** @var PersonalAccessToken|TransientToken|null $token */
        $token = $user->currentAccessToken();

        DB::transaction(function () use ($request, $user, $signOut, $token): void {
            $user->forceFill(['password' => $request->validated('password')])->save();

            $signOut->handle(
                $user,
                $request->hasSession() ? $request->session()->getId() : null,
                $token instanceof PersonalAccessToken ? $token->getKey() : null,
            );
        });

        AuthLog::tryRecord(AuthEventType::PasswordChanged, (string) $user->getKey());

        return response()->noContent();
    }
}
