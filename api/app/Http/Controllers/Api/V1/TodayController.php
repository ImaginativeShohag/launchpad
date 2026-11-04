<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ranking\TodayRanking;
use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RankedProductResource;
use App\Models\Product;
use App\Support\OptionalViewer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    /** Takes no parameters: anything in the query string or body is ignored. */
    public function __invoke(Request $request, TodayRanking $ranking, VotedProductIds $votedIds): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $products = $ranking->products($now);

        $viewer = OptionalViewer::from($request);
        $voted = $viewer === null ? null : $votedIds->handle($viewer, array_map('strval', array_values($products->modelKeys())));

        $data = $products->values()->map(
            fn (Product $product, int $index): array => (new RankedProductResource($product))
                ->ranked($index + 1, $voted === null ? null : isset($voted[$product->getKey()]))
                ->resolve($request)
        )->all();

        return OptionalViewer::cacheHeaders(response()->json([
            'data' => $data,
            'meta' => ['date' => $now->toDateString(), 'generated_at' => $now->toIso8601String()],
        ]), $viewer);
    }
}
