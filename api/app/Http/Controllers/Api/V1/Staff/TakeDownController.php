<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Product\TakeDownProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TakeDownRequest;
use App\Http\Resources\Api\V1\StaffProductResource;
use App\Models\Product;

class TakeDownController extends Controller
{
    public function __invoke(TakeDownRequest $request, string $product, TakeDownProduct $takeDown): StaffProductResource
    {
        $model = Product::query()->findOrFail($product);
        $takeDown->handle($this->authenticated($request), $model, $request->validated('reason'));

        return StaffProductResource::make($model->load(StaffProductController::RELATIONS));
    }
}
