<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Report;

/**
 * A row of the closed-report history: the report with its outcome and the target it was about.
 *
 * @mixin Report
 */
class StaffReportHistoryResource extends StaffReportItemResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);

        $this->closed(true);
    }
}
