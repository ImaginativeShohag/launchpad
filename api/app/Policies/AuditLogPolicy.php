<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Admin;
    }
}
