<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Email\UnsubscribeFromEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UnsubscribeRequest;
use Illuminate\Http\JsonResponse;

class UnsubscribeController extends Controller
{
    /** Public, authorised by the signature. The same answer whether or not anything changed or the member exists. */
    public function __invoke(UnsubscribeRequest $request, UnsubscribeFromEmail $unsubscribe): JsonResponse
    {
        $type = $request->emailType();
        $unsubscribe->handle($request->userId(), $type);

        return response()->json(['type' => $type->value, 'enabled' => false]);
    }
}
