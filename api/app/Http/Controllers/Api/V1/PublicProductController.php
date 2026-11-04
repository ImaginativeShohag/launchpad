<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Follow\FollowCounts;
use App\Actions\Ranking\ProductRank;
use App\Actions\Vote\VotedProductIds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Rules\SlugFormat;
use App\Support\OptionalViewer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicProductController extends Controller
{
    public function show(Request $request, VotedProductIds $votedIds, ProductRank $rank, FollowCounts $follows, string $slug): JsonResponse
    {
        $slug = strtolower($slug);

        // A value that cannot be a slug never reaches the database (a NUL byte would be a Postgres error).
        abort_unless(SlugFormat::matches($slug, 3, 80), 404);

        // Hidden, unknown and soft-deleted products are all the same plain 404 (D1).
        $product = Product::visible()
            ->whereMakerAvailable()
            ->with(['maker.links', 'topics', 'links', 'screenshots'])
            ->where('slug', $slug)
            ->first();

        abort_if($product === null, 404);

        // A known caller learns about their own vote only; a suspended or deleted one is a guest.
        $viewer = OptionalViewer::from($request);
        $hasVoted = $viewer === null ? null : isset($votedIds->handle($viewer, [$product->getKey()])[$product->getKey()]);

        $resource = ProductResource::make($product)
            ->withVotes($hasVoted)
            ->withRank($rank->for($product, CarbonImmutable::now('UTC')))
            ->withMakerFollow(FollowCounts::followersOf($product->maker), $viewer === null ? null : $follows->isFollowing($viewer, $product->maker));

        return OptionalViewer::cacheHeaders($resource->response(), $viewer);
    }
}
