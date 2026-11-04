<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Follow\FollowCounts;
use App\Actions\Product\CountVisibleProducts;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicUserResource;
use App\Models\User;
use App\Support\OptionalViewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(Request $request, User $user, FollowCounts $counts, CountVisibleProducts $products): JsonResponse
    {
        // A suspended or deleted caller is a guest. The owner viewing themselves is a known viewer who does not follow themselves.
        $viewer = OptionalViewer::from($request);
        $resource = PublicUserResource::make($user->load('links'))
            ->withProductsCount($products->handle($user))
            ->withFollow($counts->forUser($user), $viewer === null ? null : $counts->isFollowing($viewer, $user));

        return OptionalViewer::cacheHeaders($resource->response(), $viewer);
    }
}
