<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Follow\FollowMember;
use App\Actions\Follow\UnfollowMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FollowRequest;
use App\Http\Resources\Api\V1\FollowStateResource;
use App\Models\User;

class FollowController extends Controller
{
    public function store(FollowRequest $request, User $user, FollowMember $follow): FollowStateResource
    {
        return FollowStateResource::make($follow->handle($this->authenticated($request), $user));
    }

    public function destroy(FollowRequest $request, User $user, UnfollowMember $unfollow): FollowStateResource
    {
        return FollowStateResource::make($unfollow->handle($this->authenticated($request), $user));
    }
}
