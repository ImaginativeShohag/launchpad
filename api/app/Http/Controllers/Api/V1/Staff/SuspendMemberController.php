<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\RiskMemberSummary;
use App\Actions\AntiCheat\SuspendForAbuse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuspendMemberRequest;
use App\Http\Resources\Api\V1\RiskMemberSummaryResource;
use Carbon\CarbonImmutable;

class SuspendMemberController extends Controller
{
    /** The id is a plain string: the request judged the role first. The summary shows the member as they are now. */
    public function __invoke(SuspendMemberRequest $request, string $member, SuspendForAbuse $suspend, RiskMemberSummary $summary): RiskMemberSummaryResource
    {
        $result = $suspend->handle($this->authenticated($request), $member, $request->reason(), $request->voidVotes());
        $built = $summary->for($result['member'], CarbonImmutable::now('UTC'));
        abort_if($built === null, 404);

        return RiskMemberSummaryResource::make($built)->additional(['meta' => [
            'suspended_now' => $result['suspended_now'], 'signals_confirmed' => $result['signals_confirmed'], 'void_votes' => $result['void_votes'],
        ]]);
    }
}
