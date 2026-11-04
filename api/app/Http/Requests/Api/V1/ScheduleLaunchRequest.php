<?php

namespace App\Http\Requests\Api\V1;

class ScheduleLaunchRequest extends OwnedProductRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // `date_format` round-trips the value, so 2026-02-31 and 2026-10-2 are refused; the window is the action's job.
        return ['launch_date' => ['required', 'string', 'date_format:Y-m-d']];
    }
}
