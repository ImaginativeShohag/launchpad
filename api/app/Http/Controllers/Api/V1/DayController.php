<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ranking\DayRanking;
use App\Actions\Ranking\TodayRanking;
use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DayRequest;
use App\Http\Resources\Api\V1\RankedProductResource;
use App\Models\Product;
use App\Support\OptionalViewer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DayController extends Controller
{
    public function __invoke(DayRequest $request, TodayRanking $today, DayRanking $days, VotedProductIds $votedIds): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $day = CarbonImmutable::parse($request->day(), 'UTC')->startOfDay();
        $currentDay = $now->toDateString();

        if ($day->toDateString() > $currentDay) {
            throw new NotFoundHttpException;
        }

        if ($day->toDateString() === $currentDay) {
            $items = $today->products($now)->values()->map(fn (Product $product, int $index): array => ['product' => $product, 'rank' => $index + 1])->all();
            $frozen = false;
        } else {
            ['frozen' => $frozen, 'items' => $items] = $days->handle($day);
        }

        $viewer = OptionalViewer::from($request);
        $voted = $viewer === null ? null : $votedIds->handle($viewer, array_values(array_map(fn (array $item): string => (string) $item['product']->getKey(), $items)));

        $data = array_values(array_map(
            fn (array $item): array => (new RankedProductResource($item['product']))
                ->ranked($item['rank'], $voted === null ? null : isset($voted[$item['product']->getKey()]))
                ->resolve($request),
            $items,
        ));

        return OptionalViewer::cacheHeaders(response()->json([
            'data' => $data,
            'meta' => ['date' => $day->toDateString(), 'frozen' => $frozen],
        ]), $viewer);
    }
}
