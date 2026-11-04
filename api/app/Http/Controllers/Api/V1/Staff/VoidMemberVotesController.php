<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\VoidVotesByStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VoidMemberVotesRequest;
use Illuminate\Http\JsonResponse;

class VoidMemberVotesController extends Controller
{
    public function __invoke(VoidMemberVotesRequest $request, string $member, VoidVotesByStaff $void): JsonResponse
    {
        $result = $void->forMember($this->authenticated($request), $member, $request->reason(), $request->productId());

        return response()->json(['data' => [
            'voided' => $result->voided, 'skipped_frozen' => $result->skippedFrozen, 'failed' => $result->failed, 'has_more' => $result->hasMore,
        ]]);
    }
}
