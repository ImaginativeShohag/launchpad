<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\CreateDraft;
use App\Actions\Product\DeleteDraft;
use App\Actions\Product\UpdateProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateProductRequest;
use App\Http\Requests\Api\V1\OwnedProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\FounderProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    /** Relations the founder resource shows after a write. */
    public const RELATIONS = ['links', 'topics', 'screenshots', 'reviews'];

    public function store(CreateProductRequest $request, CreateDraft $create): JsonResponse
    {
        $product = $create->handle($this->authenticated($request), $request->validated());

        return FounderProductResource::make($product->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $update): FounderProductResource
    {
        $updated = $update->handle($this->authenticated($request), $product, $request->validated());

        return FounderProductResource::make($updated->load(self::RELATIONS));
    }

    public function destroy(OwnedProductRequest $request, Product $product, DeleteDraft $delete): Response
    {
        $delete->handle($this->authenticated($request), $product);

        return response()->noContent();
    }
}
