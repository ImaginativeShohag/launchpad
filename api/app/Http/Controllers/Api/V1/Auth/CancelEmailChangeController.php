<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CancelEmailChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancelEmailChangeController extends Controller
{
    public function __invoke(Request $request, CancelEmailChange $cancel): JsonResponse
    {
        $cleared = $cancel->handle($request->query('id'), $request->query('hash'));

        // Never claim a cancellation that did not happen: if the change was already confirmed, say so.
        return $cleared
            ? response()->json(['message' => 'The email change was cancelled.', 'code' => 'cancelled'])
            : response()->json([
                'message' => 'There is no pending email change. If your email address was already changed and this was not you, reset your password.',
                'code' => 'no_pending_change',
            ]);
    }
}
