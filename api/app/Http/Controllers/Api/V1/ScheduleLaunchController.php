<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\ScheduleLaunch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ScheduleLaunchRequest;
use App\Http\Resources\Api\V1\FounderProductResource;
use App\Models\Product;

class ScheduleLaunchController extends Controller
{
    public function __invoke(ScheduleLaunchRequest $request, Product $product, ScheduleLaunch $schedule): FounderProductResource
    {
        $updated = $schedule->handle($this->authenticated($request), $product, $request->validated('launch_date'));

        return FounderProductResource::make($updated->load(ProductController::RELATIONS));
    }
}
