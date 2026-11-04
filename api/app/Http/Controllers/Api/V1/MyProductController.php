<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\AttachLatestReviews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MyProductsRequest;
use App\Http\Requests\Api\V1\OwnedProductRequest;
use App\Http\Resources\Api\V1\FounderProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyProductController extends Controller
{
    public function index(MyProductsRequest $request, AttachLatestReviews $latestReviews): AnonymousResourceCollection
    {
        // Matches products_maker_id_created_at_id_index. Soft-deleted drafts are excluded by the model.
        $products = Product::query()
            ->where('maker_id', $this->authenticated($request)->getKey())
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->with(['topics', 'screenshots'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        $latestReviews->handle($products->getCollection());

        return FounderProductResource::collection($products);
    }

    public function show(OwnedProductRequest $request, Product $product): FounderProductResource
    {
        return FounderProductResource::make($product->load(ProductController::RELATIONS));
    }
}
