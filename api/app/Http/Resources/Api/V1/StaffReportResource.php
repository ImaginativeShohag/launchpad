<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the staff queue: a reported target with the count, the reasons and the score of its open reports. The
 * resource is the array ReportQueue::open() builds. Staff only; free text in it (names, excerpts) is data, to be
 * rendered as text.
 *
 * @mixin \stdClass
 */
class StaffReportResource extends JsonResource
{
    public const STAMP = 'Y-m-d\TH:i:s.u\Z';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{type: string, target: array<string, mixed>, open_reports: int, reasons: array<string, int>, score: float, oldest_report_at: CarbonInterface} $row */
        $row = $this->resource;

        return [
            'type' => $row['type'],
            'target' => $row['target'],
            'open_reports' => $row['open_reports'],
            // An object even when empty never happens (a row has at least one eligible report).
            'reasons' => $row['reasons'],
            'score' => $row['score'],
            'oldest_report_at' => $row['oldest_report_at']->copy()->utc()->format(self::STAMP),
        ];
    }
}
