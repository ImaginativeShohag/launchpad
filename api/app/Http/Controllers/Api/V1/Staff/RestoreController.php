<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Product\RestoreProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestoreProductRequest;
use App\Http\Resources\Api\V1\StaffProductResource;
use App\Models\Product;

class RestoreController extends Controller
{
    public function __invoke(RestoreProductRequest $request, string $product, RestoreProduct $restore): StaffProductResource
    {
        $model = Product::query()->findOrFail($product);
        $restore->handle($this->authenticated($request), $model);

        return StaffProductResource::make($model->load(StaffProductController::RELATIONS));
    }
}
