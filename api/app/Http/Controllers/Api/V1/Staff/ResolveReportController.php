<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Report\ResolveReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResolveReportRequest;
use App\Http\Resources\Api\V1\ResolveOutcomeResource;

class ResolveReportController extends Controller
{
    /** `type` and `id` are plain strings: the request judges the role first and the route parts after, then the action looks the target up. */
    public function __invoke(ResolveReportRequest $request, string $type, string $id, ResolveReport $resolve): ResolveOutcomeResource
    {
        /** @var 'comment'|'product' $type */
        return ResolveOutcomeResource::make($resolve->handle($this->authenticated($request), $type, $id, $request->action(), $request->note()));
    }
}
