<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Report;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One reported target in full, its open reports and its latest closed ones. The resource is the array ReportQueue::detail() builds.
 *
 * @mixin \stdClass
 */
class StaffReportDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{type: string, target: array<string, mixed>, open_reports: Collection<int, Report>, open_total: int, closed_reports: Collection<int, Report>} $detail */
        $detail = $this->resource;
        $target = $detail['target'];

        foreach (['created_at', 'removed_at'] as $key) {
            if (array_key_exists($key, $target)) {
                $moment = $target[$key];
                $target[$key] = $moment instanceof CarbonInterface ? $moment->copy()->utc()->format(StaffReportResource::STAMP) : null;
            }
        }

        return [
            'type' => $detail['type'],
            'target' => $target,
            'open_reports' => $detail['open_reports']->map(fn (Report $report) => StaffReportItemResource::make($report)->toArray($request))->all(),
            'open_total' => $detail['open_total'],
            'closed_reports' => $detail['closed_reports']->map(fn (Report $report) => StaffReportItemResource::make($report)->closed()->toArray($request))->all(),
        ];
    }
}
