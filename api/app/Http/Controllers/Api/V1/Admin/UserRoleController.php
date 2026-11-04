<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ChangeUserRole;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeRoleRequest;
use App\Http\Resources\Api\V1\AdminUserResource;
use App\Models\User;

class UserRoleController extends Controller
{
    public function __invoke(ChangeRoleRequest $request, User $user, ChangeUserRole $change): AdminUserResource
    {
        $updated = $change->handle(
            $this->authenticated($request),
            $user,
            Role::from($request->validated('role')),
        );

        return new AdminUserResource($updated);
    }
}
