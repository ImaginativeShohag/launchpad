<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Follow\FollowTopic;
use App\Actions\Follow\UnfollowTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FollowRequest;
use App\Http\Resources\Api\V1\FollowStateResource;

class TopicFollowController extends Controller
{
    // `$topic` is a plain slug string on purpose: Topic has no slug route key, and the action answers every unknown case with one 404.
    public function store(FollowRequest $request, string $topic, FollowTopic $follow): FollowStateResource
    {
        return FollowStateResource::make($follow->handle($this->authenticated($request), $topic));
    }

    public function destroy(FollowRequest $request, string $topic, UnfollowTopic $unfollow): FollowStateResource
    {
        return FollowStateResource::make($unfollow->handle($this->authenticated($request), $topic));
    }
}
