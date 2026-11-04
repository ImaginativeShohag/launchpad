<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfirmEmailChangeController extends Controller
{
    /** Guest route: the signed link is the credential. It signs nobody in. */
    public function __invoke(Request $request, ConfirmEmailChange $confirm): JsonResponse
    {
        $confirm->handle($request->query('id'), $request->query('hash'));

        return response()->json(['message' => 'Your email address has been updated. Sign in again.']);
    }
}
