<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Follow\FollowingFeed;
use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FeedRequest;
use App\Http\Resources\Api\V1\FeedCollection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;

class FeedController extends Controller
{
    /** Never cached: `me/*` is no-store, so a follow or an unfollow shows at once. */
    public function __invoke(FeedRequest $request, FollowingFeed $feed, VotedProductIds $votedIds): JsonResponse
    {
        $me = $this->authenticated($request);
        $hasFollows = $feed->hasFollows($me);
        $page = $feed->page($me, $request->perPage(), $hasFollows);
        $products = new EloquentCollection($page->items());

        $collection = new FeedCollection(
            $page,
            $votedIds->handle($me, array_values(array_map('strval', $products->modelKeys()))),
            $feed->reasons($me, $products),
        );

        return $collection->additional(['meta' => ['has_follows' => $hasFollows]])->response();
    }
}
