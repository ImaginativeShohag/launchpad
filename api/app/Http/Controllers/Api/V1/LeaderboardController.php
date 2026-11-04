<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ranking\Leaderboard;
use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LeaderboardRequest;
use App\Http\Resources\Api\V1\RankedProductResource;
use App\Models\Product;
use App\Support\OptionalViewer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    public function __invoke(LeaderboardRequest $request, Leaderboard $leaderboard, VotedProductIds $votedIds): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $products = $leaderboard->products($request->period(), $request->topic(), $now);

        $viewer = OptionalViewer::from($request);
        $voted = $viewer === null ? null : $votedIds->handle($viewer, array_map('strval', array_values($products->modelKeys())));

        $data = $products->values()->map(
            fn (Product $product, int $index): array => (new RankedProductResource($product))
                ->ranked($index + 1, $voted === null ? null : isset($voted[$product->getKey()]))
                ->resolve($request)
        )->all();

        return OptionalViewer::cacheHeaders(response()->json([
            'data' => $data,
            'meta' => ['period' => $request->period(), 'topic' => $request->topic(), 'generated_at' => $now->toIso8601String()],
        ]), $viewer);
    }
}
