<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Report\ReportQueue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StaffReportRequest;
use App\Http\Resources\Api\V1\StaffReportResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportQueueController extends Controller
{
    /** The open queue, one row per reported target, top 100, no cursor. `no-store` through the `staff/*` prefix. */
    public function __invoke(StaffReportRequest $request, ReportQueue $queue): AnonymousResourceCollection
    {
        $open = $queue->open($request->type());

        return StaffReportResource::collection($open->rows)->additional(['meta' => ['total_targets' => $open->total, 'truncated' => $open->truncated()]]);
    }
}
