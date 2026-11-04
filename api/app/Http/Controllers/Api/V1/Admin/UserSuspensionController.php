<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\RestoreUser;
use App\Actions\Admin\SuspendUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestoreUserRequest;
use App\Http\Requests\Api\V1\SuspendUserRequest;
use App\Http\Resources\Api\V1\SuspensionResource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class UserSuspensionController extends Controller
{
    /**
     * `$user` is the handle as a plain string, not a bound model: the request has judged the role before the member is
     * looked up, so a non-admin gets the same 403 for an existing and an unknown handle.
     */
    public function suspend(SuspendUserRequest $request, string $user, SuspendUser $suspend): SuspensionResource
    {
        return SuspensionResource::make($suspend->handle($this->authenticated($request), $this->target($user), $request->reason()));
    }

    public function restore(RestoreUserRequest $request, string $user, RestoreUser $restore): SuspensionResource
    {
        return SuspensionResource::make($restore->handle($this->authenticated($request), $this->target($user)));
    }

    /** The same lookup as the `{user}` route binding: a handle, never a deleted account. */
    private function target(string $handle): User
    {
        $target = (new User)->resolveRouteBinding($handle);

        if (! $target instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return $target;
    }
}
