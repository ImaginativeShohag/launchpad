<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Dashboard\Window;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/** `granularity` (hour or day, default hour), `from` and `to` of the votes-and-views series. */
class DashboardSeriesRequest extends DashboardRangeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'granularity' => ['sometimes', 'bail', 'string', Rule::in(['hour', 'day'])],
            ...parent::rules(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function makeWindow(array $data): Window
    {
        return Window::series(
            $this->product(),
            self::text($data['granularity'] ?? null),
            self::text($data['from'] ?? null),
            self::text($data['to'] ?? null),
            CarbonImmutable::now('UTC'),
        );
    }
}
