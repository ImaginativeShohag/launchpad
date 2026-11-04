<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ConfirmEmail;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, ConfirmEmail $confirm): UserResource
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // The signature (checked by the signed:relative middleware) proves the link is ours;
        // these checks prove it was issued for this member and this address.
        // Query values can be arrays (?id[]=x): anything that is not a string fails the check.
        $id = $request->query('id');
        $hash = $request->query('hash');

        abort_unless(is_string($id) && hash_equals((string) $user->getKey(), $id), 403);
        abort_unless(is_string($hash) && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        $confirm->handle($user);

        return new UserResource($user->refresh());
    }
}
