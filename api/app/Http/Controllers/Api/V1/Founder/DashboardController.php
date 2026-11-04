<?php

namespace App\Http\Controllers\Api\V1\Founder;

use App\Actions\Dashboard\DashboardCache;
use App\Actions\Dashboard\RankHistory;
use App\Actions\Dashboard\Referrers;
use App\Actions\Dashboard\Series;
use App\Actions\Dashboard\Summary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DashboardRangeRequest;
use App\Http\Requests\Api\V1\DashboardRequest;
use App\Http\Requests\Api\V1\DashboardSeriesRequest;
use App\Http\Resources\Api\V1\DashboardPayloadResource;
use App\Http\Resources\Api\V1\DashboardSummaryResource;

/**
 * The founder dashboard reads. The same four methods serve `me/products/{product}/dashboard` (the maker) and the
 * `staff/` mirror (read-only support); the request's authorize() decides which product the caller may see. Ids are plain
 * strings on purpose.
 */
class DashboardController extends Controller
{
    public function summary(DashboardRequest $request, Summary $summary, DashboardCache $cache): DashboardSummaryResource
    {
        $product = $request->product();

        return DashboardSummaryResource::make($cache->remember($product->getKey(), 'summary', [], fn () => $summary->handle($product), $product->status->value));
    }

    public function series(DashboardSeriesRequest $request, Series $series, DashboardCache $cache): DashboardPayloadResource
    {
        $product = $request->product();
        $window = $request->window();

        return DashboardPayloadResource::make($cache->remember($product->getKey(), 'series', $request->validated(), fn () => $series->handle($product, $window), $product->status->value));
    }

    public function rank(DashboardRequest $request, RankHistory $rank, DashboardCache $cache): DashboardPayloadResource
    {
        $product = $request->product();

        return DashboardPayloadResource::make($cache->remember($product->getKey(), 'rank', [], fn () => $rank->handle($product), $product->status->value));
    }

    public function referrers(DashboardRangeRequest $request, Referrers $referrers, DashboardCache $cache): DashboardPayloadResource
    {
        $product = $request->product();
        $window = $request->window();

        return DashboardPayloadResource::make($cache->remember($product->getKey(), 'referrers', $request->validated(), fn () => $referrers->handle($product, $window), $product->status->value));
    }
}
