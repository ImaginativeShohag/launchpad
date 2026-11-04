<?php

namespace App\Actions\AntiCheat\Concerns;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The actor's role for a staff action, read from the database with a plain (unlocked) read: the model the request carries
 * may be stale (demoted or suspended since the middleware ran), and a plain read takes no lock, so calling it first keeps
 * the global lock order intact (a user lock must never come before a product row).
 */
trait RequiresActiveStaff
{
    /**
     * @throws AuthorizationException unless the actor is, right now, an active reviewer or admin
     */
    private function assertStaff(User $actor): void
    {
        if (! in_array($this->activeRole($actor), [Role::Reviewer->value, Role::Admin->value], true)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @throws AuthorizationException unless the actor is, right now, an active admin
     */
    private function assertAdmin(User $actor): void
    {
        if ($this->activeRole($actor) !== Role::Admin->value) {
            throw new AuthorizationException;
        }
    }

    private function activeRole(User $actor): mixed
    {
        return DB::table('users')->where('id', $actor->getKey())->whereNull('deleted_at')->whereNull('suspended_at')->value('role');
    }
}
