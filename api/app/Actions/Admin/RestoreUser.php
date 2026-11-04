<?php

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RestoreUser
{
    /**
     * Lifts a suspension. Idempotent: a member who is not suspended is left alone and nothing is audited. Sessions and
     * tokens do not come back (the member signs in again); the old reason is kept in the audit row, not on the user.
     * Same locks as SuspendUser: the active-admin set in id order, then the target row.
     *
     * @throws AuthorizationException when the actor is no longer an active admin
     * @throws ModelNotFoundException when the target no longer exists or was deleted
     */
    public function handle(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            $admins = User::query()->where('role', Role::Admin->value)->whereNull('deleted_at')
                ->orderBy('id')->lockForUpdate()->pluck('id');

            if (! $admins->contains($actor->getKey())) {
                throw new AuthorizationException;
            }

            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isDeleted()) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }

            if ($locked->isSuspended()) {
                $previous = $locked->suspended_reason;
                $locked->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();
                Audit::record('user.restored', $actor, $locked, ['previous_reason' => $previous]);
            }

            $target->setRawAttributes($locked->getAttributes(), true);

            return $target;
        });
    }
}
