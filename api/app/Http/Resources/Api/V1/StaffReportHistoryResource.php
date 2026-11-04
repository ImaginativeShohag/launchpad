<?php

namespace App\Http\Resources\Api\V1;

/**
 * A row of the closed-report history: the report with its outcome and the target it was about.
 *
 * @mixin \App\Models\Report
 */
class StaffReportHistoryResource extends StaffReportItemResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);

        $this->closed(true);
    }
}
