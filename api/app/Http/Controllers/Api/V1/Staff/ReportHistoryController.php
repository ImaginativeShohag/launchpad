<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Report\ReportQueue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportHistoryRequest;
use App\Http\Resources\Api\V1\StaffReportHistoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportHistoryController extends Controller
{
    /** Closed reports, newest first. */
    public function __invoke(ReportHistoryRequest $request, ReportQueue $queue): AnonymousResourceCollection
    {
        $type = $request->type();
        $page = $queue->history($type, $request->perPage())
            // The next/previous links must carry the filter and the page size, or page two would change them.
            ->appends(array_filter(['per_page' => $request->perPage(), 'type' => $type]));

        return StaffReportHistoryResource::collection($page);
    }
}
