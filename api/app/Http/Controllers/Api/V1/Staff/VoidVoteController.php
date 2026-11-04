<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\VoidVotesByStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VoidVoteRequest;
use Illuminate\Http\JsonResponse;

class VoidVoteController extends Controller
{
    /** `voided: false` means the vote was already voided. The id stays a plain string: the request judged the role first. */
    public function __invoke(VoidVoteRequest $request, string $vote, VoidVotesByStaff $void): JsonResponse
    {
        return response()->json(['data' => ['voided' => $void->vote($this->authenticated($request), $vote, $request->reason())]]);
    }
}
