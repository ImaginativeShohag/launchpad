<?php

namespace App\Actions\Admin;

use App\Actions\Auth\SignOutEverywhere;
use App\Enums\Role;
use App\Exceptions\CommunityRuleViolation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SuspendUser
{
    public function __construct(private readonly SignOutEverywhere $signOut) {}

    /**
     * Suspends a member or a reviewer: they are signed out everywhere (sessions, tokens, remember token) and blocked
     * from every authenticated action; their products, comments, votes and follows stay as they are. Admins can never
     * be suspended, so the last admin cannot be locked out, and nobody suspends themselves.
     *
     * Same lock pattern as ChangeUserRole (the models handed in may be stale): the active-admin set in id order, which
     * the actor must belong to, then the target row. No product row is needed, no product changes. The audit insert
     * takes FOR KEY SHARE on the actor row last.
     *
     * Already suspended: nothing changes (the first reason and time stay), nothing is audited, nobody is signed out again.
     *
     * @throws AuthorizationException when the actor is no longer an active admin
     * @throws ModelNotFoundException when the target no longer exists or was deleted
     * @throws CommunityRuleViolation `cannot_suspend` for oneself or an admin
     */
    public function handle(User $actor, User $target, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $reason): User {
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
                throw CommunityRuleViolation::cannotSuspend();
            }

            if (! $locked->isSuspended()) {
                $locked->forceFill(['suspended_at' => now(), 'suspended_reason' => $reason])->save();
                $this->signOut->handle($locked);
                Audit::record('user.suspended', $actor, $locked, ['reason' => $reason]);
            }

            $target->setRawAttributes($locked->getAttributes(), true);

            return $target;
        });
    }
}
