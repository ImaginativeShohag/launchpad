<?php

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ChangeUserRole
{
    /**
     * Moves a member between member and reviewer. Admins are never changed here (the last admin can
     * therefore never be demoted, and two admins cannot demote each other); admins come only from
     * `launchpad:make-admin`.
     *
     * Everything is re-checked on locked rows, because the models handed in may be stale. Lock order is
     * the global one (see DeleteAccount): the active-admin set in id order first, then the target row.
     * The actor is an admin, so their own row is inside the set.
     *
     * @throws AuthorizationException when the actor is no longer an active admin, or the target is the
     *                                actor or an admin
     * @throws ModelNotFoundException when the target no longer exists or was deleted
     */
    public function handle(User $actor, User $target, Role $role): User
    {
        return DB::transaction(function () use ($actor, $target, $role): User {
            $admins = User::query()->where('role', Role::Admin->value)->whereNull('deleted_at')
                ->orderBy('id')->lockForUpdate()->pluck('id');

            if (! $admins->contains($actor->getKey())) {
                throw new AuthorizationException;
            }

            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isDeleted()) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }

            if ($locked->is($actor) || $locked->role === Role::Admin) {
                throw new AuthorizationException;
            }

            $before = $locked->role;

            if ($before !== $role) {
                $locked->forceFill(['role' => $role])->save();

                Audit::record('user.role_changed', $actor, $locked, [
                    'before' => $before->value,
                    'after' => $role->value,
                ]);
            }

            $target->setRawAttributes($locked->getAttributes(), true);

            return $target;
        });
    }
}
