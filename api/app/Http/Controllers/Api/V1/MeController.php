<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($this->authenticated($request)->load('links'));
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $update): UserResource
    {
        $user = $update->handle($this->authenticated($request), $request->validated());

        return new UserResource($user->load('links'));
    }
}
