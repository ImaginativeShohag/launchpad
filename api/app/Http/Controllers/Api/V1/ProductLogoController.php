<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\RemoveLogo;
use App\Actions\Product\SetLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProductRequest;
use App\Http\Requests\Api\V1\ProductLogoRequest;
use App\Http\Resources\Api\V1\FounderProductResource;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class ProductLogoController extends Controller
{
    public function store(ProductLogoRequest $request, Product $product, SetLogo $set): FounderProductResource
    {
        $file = $request->file('logo');
        abort_unless($file instanceof UploadedFile, 422);

        $updated = $set->handle($this->authenticated($request), $product, $file);

        return FounderProductResource::make($updated->load(ProductController::RELATIONS));
    }

    public function destroy(OwnedProductRequest $request, Product $product, RemoveLogo $remove): Response
    {
        $remove->handle($this->authenticated($request), $product);

        return response()->noContent();
    }
}
