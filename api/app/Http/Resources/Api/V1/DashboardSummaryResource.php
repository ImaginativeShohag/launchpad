<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The summary tiles with their keys written out (a new Action field never leaks into the response unnoticed).
 *
 * @mixin \stdClass
 */
class DashboardSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $s */
        $s = $this->resource;

        return [
            'product_id' => $s['product_id'],
            'name' => $s['name'],
            'slug' => $s['slug'],
            'status' => $s['status'],
            'phase' => $s['phase'],
            'launch_date' => $s['launch_date'],
            'taken_down' => $s['taken_down'],
            'views' => $s['views'],
            'votes' => $s['votes'],
            'comments' => $s['comments'],
            'conversion' => $s['conversion'],
            'current_rank' => $s['current_rank'],
            'best_rank' => $s['best_rank'],
            'final_rank' => $s['final_rank'],
            'top_source' => $s['top_source'],
            'timeline_available' => $s['timeline_available'],
            'as_of' => $s['as_of'],
        ];
    }
}
