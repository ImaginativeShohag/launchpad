<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Report\ReportQueue;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One report as staff see it, reporter identity included (handle only, never an email). Expects `reporter` and
 * `handler` loaded. Open reports carry no outcome; closed ones add it; the history list also names the target.
 *
 * @mixin Report
 */
class StaffReportItemResource extends JsonResource
{
    private bool $closed = false;

    private bool $withTarget = false;

    /** Adds the outcome (status, handler, time, note). */
    public function closed(bool $withTarget = false): static
    {
        $this->closed = true;
        $this->withTarget = $withTarget;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id];

        if ($this->withTarget) {
            $data['type'] = $this->reportable_type;
            $data['target_id'] = $this->reportable_id;
        }

        $data += [
            'reporter' => ['id' => $this->reporter->id, 'handle' => $this->reporter->handle],
            'reporter_eligible' => ReportQueue::reporterEligible($this->reporter),
            'reason' => $this->reason->value,
            'details' => $this->details,
            'created_at' => $this->created_at->copy()->utc()->format(StaffReportResource::STAMP),
        ];

        if ($this->closed) {
            $data += [
                'status' => $this->status->value,
                'handled_by' => $this->handler === null ? null : ['id' => $this->handler->id, 'handle' => $this->handler->handle],
                'handled_at' => $this->handled_at?->copy()->utc()->format(StaffReportResource::STAMP),
                'resolution_note' => $this->resolution_note,
            ];
        }

        return $data;
    }
}
