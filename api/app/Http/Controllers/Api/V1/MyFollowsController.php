<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MyFollowedUsersRequest;
use App\Http\Resources\Api\V1\FollowedUserResource;
use App\Http\Resources\Api\V1\TopicResource;
use App\Models\Topic;
use App\Models\UserFollow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyFollowsController extends Controller
{
    /** The members I follow, newest follow first. Only mine: there is no route that lists anyone else's follows or followers. */
    public function users(MyFollowedUsersRequest $request): AnonymousResourceCollection
    {
        // Matches user_follows_follower_created_index. A deleted account's follows were removed with it, so no filter is needed.
        $follows = UserFollow::query()
            ->where('follower_id', $this->authenticated($request)->getKey())
            ->with('followed')
            ->orderByDesc('created_at')
            ->orderByDesc('followed_id')
            ->cursorPaginate($request->perPage())
            ->appends(['per_page' => $request->perPage()]);

        return FollowedUserResource::collection($follows);
    }

    /** The topic list is small and fixed, so it is not paginated. */
    public function topics(Request $request): AnonymousResourceCollection
    {
        $topics = Topic::query()
            ->whereIn('id', fn ($ids) => $ids->select('topic_id')->from('topic_follows')->where('user_id', $this->authenticated($request)->getKey()))
            ->orderBy('position')->orderBy('name')->orderBy('id')
            ->get();

        return TopicResource::collection($topics);
    }
}
