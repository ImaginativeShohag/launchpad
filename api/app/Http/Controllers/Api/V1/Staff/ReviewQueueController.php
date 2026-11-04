<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewQueueRequest;
use App\Http\Resources\Api\V1\StaffProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewQueueController extends Controller
{
    public function __invoke(ReviewQueueRequest $request): AnonymousResourceCollection
    {
        // Oldest submission first; id breaks ties. Matches products_status_submitted_at_id_index.
        $products = Product::query()
            ->where('status', ProductStatus::Pending->value)
            ->with(['maker', 'topics'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->cursorPaginate($request->perPage());

        return StaffProductResource::collection($products);
    }
}
