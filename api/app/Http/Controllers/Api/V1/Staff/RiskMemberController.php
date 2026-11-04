<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\RiskMemberQuery;
use App\Actions\AntiCheat\RiskMemberSummary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RiskMemberIndexRequest;
use App\Http\Requests\Api\V1\StaffRiskRequest;
use App\Http\Resources\Api\V1\RiskMemberResource;
use App\Http\Resources\Api\V1\RiskMemberSummaryResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RiskMemberController extends Controller
{
    /** Members by risk score, highest first. */
    public function index(RiskMemberIndexRequest $request, RiskMemberQuery $members): AnonymousResourceCollection
    {
        $suspended = $request->suspended();
        $page = $members->page($request->minScore(), $suspended, $request->perPage())
            ->appends(array_filter([
                'min_score' => $request->minScore(), 'per_page' => $request->perPage(),
                'suspended' => $suspended === null ? null : ($suspended ? 'true' : 'false'),
            ], fn ($v) => $v !== null));

        return RiskMemberResource::collection($page);
    }

    /** The id is a plain string: the request judges the role first, and only then is the member looked up. */
    public function show(StaffRiskRequest $request, string $member, RiskMemberSummary $summary): RiskMemberSummaryResource
    {
        $built = $summary->for($member, CarbonImmutable::now('UTC'));
        abort_if($built === null, 404);

        return RiskMemberSummaryResource::make($built);
    }
}
