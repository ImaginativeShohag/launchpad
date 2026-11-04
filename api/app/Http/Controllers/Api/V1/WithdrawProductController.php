<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\WithdrawProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProductRequest;
use App\Http\Resources\Api\V1\FounderProductResource;
use App\Models\Product;

class WithdrawProductController extends Controller
{
    public function __invoke(OwnedProductRequest $request, Product $product, WithdrawProduct $withdraw): FounderProductResource
    {
        $updated = $withdraw->handle($this->authenticated($request), $product);

        return FounderProductResource::make($updated->load(ProductController::RELATIONS));
    }
}
