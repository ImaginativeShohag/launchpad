<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UserProductsRequest;
use App\Http\Resources\Api\V1\ProductCardCollection;
use App\Models\Product;
use App\Models\User;
use App\Support\OptionalViewer;
use Illuminate\Http\JsonResponse;

class UserProductController extends Controller
{
    public function index(UserProductsRequest $request, User $user, VotedProductIds $votedIds): JsonResponse
    {
        // Matches products_maker_id_launched_at_id_index. Live products always have launched_at (the scheduler
        // sets it); the filter keeps a damaged row from putting a null into a cursor.
        $products = Product::visible()
            ->where('maker_id', $user->getKey())
            ->whereNotNull('launched_at')
            ->with('topics')
            ->orderByDesc('launched_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        // A known viewer also learns their own votes (one query for the page); a suspended or deleted caller is a guest.
        $viewer = OptionalViewer::from($request);
        $voted = $viewer === null ? null : $votedIds->handle($viewer, array_map('strval', array_values($products->getCollection()->modelKeys())));

        return OptionalViewer::cacheHeaders((new ProductCardCollection($products, $voted))->response(), $viewer);
    }
}
