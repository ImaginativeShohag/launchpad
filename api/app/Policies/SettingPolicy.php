<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class SettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === Role::Admin;
    }
}
