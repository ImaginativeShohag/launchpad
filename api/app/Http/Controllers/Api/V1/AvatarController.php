<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\RemoveAvatar;
use App\Actions\Account\StoreAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AvatarRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class AvatarController extends Controller
{
    public function store(AvatarRequest $request, StoreAvatar $store): UserResource
    {
        $file = $request->file('avatar');
        abort_unless($file instanceof UploadedFile, 422);

        $user = $store->handle($this->authenticated($request), $file);

        return new UserResource($user->load('links'));
    }

    public function destroy(Request $request, RemoveAvatar $remove): Response
    {
        $remove->handle($this->authenticated($request));

        return response()->noContent();
    }
}
