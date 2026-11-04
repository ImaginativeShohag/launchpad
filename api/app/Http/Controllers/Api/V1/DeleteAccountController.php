<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\DeleteAccount;
use App\Exceptions\LastAdminException;
use App\Exceptions\ProductRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Support\PasswordCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteAccountController extends Controller
{
    public function __invoke(DeleteAccountRequest $request, DeleteAccount $delete): Response|JsonResponse
    {
        $user = $this->authenticated($request);

        try {
            $decision = Gate::inspect('deleteAccount', $user);

            if ($decision->denied()) {
                if ($decision->code() === 'has_active_product') {
                    throw ProductRuleViolation::hasActiveProduct(); // renders as 403 {"code":"has_active_product"}
                }

                // The last-admin denial has its own body (below); every other denial is the standard 403.
                throw $decision->code() === 'last_admin' ? new LastAdminException : new AuthorizationException($decision->message(), 403);
            }

            if ($user->hasPassword() && ! PasswordCheck::verify($request->validated('current_password') ?? '', $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
            }

            // False when a concurrent duplicate request already deleted it: still a 204.
            $delete->handle($user);
        } catch (LastAdminException) {
            // One path to this 403, whether the policy or the locked re-check in the action caught it.
            return response()->json([
                'message' => 'You are the last admin. Make someone else an admin first.',
                'code' => 'last_admin',
            ], 403);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        return response()->noContent();
    }
}
