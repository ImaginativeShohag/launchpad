<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Search\SearchProducts;
use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Support\OptionalViewer;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchProducts $search, VotedProductIds $votedIds): JsonResponse
    {
        $query = $request->searchQuery();
        $sort = $request->sort();
        $page = $search->handle($query, $request->topic(), $sort, $request->perPage(), $request->cursor());

        // A suspended or deleted caller is a guest.
        $viewer = OptionalViewer::from($request);
        $voted = $viewer === null ? null : $votedIds->handle($viewer, array_map('strval', array_values($page->products->modelKeys())));

        $data = $page->products->map(
            fn (Product $product): array => (new ProductResource($product))
                ->withVotes($voted === null ? null : isset($voted[$product->getKey()]))
                ->resolve($request)
        )->all();

        // The echoed terms are the server's normalised tokens. The next link repeats them, unless NFKC expansion made them
        // longer than a request may be (then the input as typed, which is within the limit and parses to the same tokens).
        $phrase = $query?->phrase();
        $linkQuery = $phrase !== null && mb_strlen($phrase) <= 100 ? $phrase : $request->typed();
        $next = $page->nextCursor === null ? null : url('/api/v1/search').'?'.http_build_query(array_filter([
            'q' => $linkQuery,
            'topic' => $request->topic(),
            'sort' => $sort->value,
            'per_page' => $request->perPage(),
            'cursor' => $page->nextCursor,
        ], fn ($value) => $value !== null), '', '&', PHP_QUERY_RFC3986);

        return OptionalViewer::cacheHeaders(response()->json([
            'data' => $data,
            'links' => ['next' => $next],
            'meta' => [
                'query' => $phrase,
                'topic' => $request->topic(),
                'sort' => $sort->value,
                'per_page' => $request->perPage(),
                'next_cursor' => $page->nextCursor,
            ],
        ]), $viewer);
    }
}
