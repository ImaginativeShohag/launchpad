<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Report\ReportQueue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StaffReportRequest;
use App\Http\Resources\Api\V1\StaffReportDetailResource;
use Illuminate\Support\Str;

class ReportDetailController extends Controller
{
    /** `type` and `id` are plain strings: the request judges the role first, and only then is the target looked up. */
    public function __invoke(StaffReportRequest $request, string $type, string $id, ReportQueue $queue): StaffReportDetailResource
    {
        abort_unless(($type === 'comment' || $type === 'product') && Str::isUlid($id), 404);

        $detail = $queue->detail($type, $id);
        abort_if($detail === null, 404);

        return StaffReportDetailResource::make($detail);
    }
}
