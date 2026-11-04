<?php

namespace App\Policies;

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Members delete only themselves, and the last remaining admin cannot (there must always be one).
     * Suspended members never get this far: the `active` middleware blocks them on the endpoint, so a
     * suspension (and its reason) cannot be erased by deleting the account. Members with a pending, approved,
     * scheduled or live product cannot either (sub-project 2).
     */
    public function deleteAccount(User $actor, User $target): Response
    {
        if (! $actor->is($target)) {
            return Response::deny();
        }

        if (self::isLastAdmin($target)) {
            return Response::deny('You are the last admin. Make someone else an admin first.', 'last_admin');
        }

        $blocking = array_map(fn (ProductStatus $status) => $status->value, ProductStatus::blockingDeletion());

        if (Product::query()->where('maker_id', $target->getKey())->whereIn('status', $blocking)->exists()) {
            return Response::deny('Withdraw or finish your products before deleting your account.', 'has_active_product');
        }

        return Response::allow();
    }

    /** Admins move members between member and reviewer; never themselves, never another admin. */
    public function changeRole(User $actor, User $target): bool
    {
        return $actor->role === Role::Admin
            && ! $actor->is($target)
            && $target->role !== Role::Admin;
    }

    /**
     * Class-level: only admins suspend and restore members. Whether the target may be suspended (not oneself, not an
     * admin) is judged by the action on the locked rows.
     */
    public function suspend(User $actor): bool
    {
        return $actor->role === Role::Admin;
    }

    /** Class-level: only admins browse the member directory (it shows e-mails and risk scores). */
    public function listMembers(User $actor): bool
    {
        return $actor->role === Role::Admin;
    }

    /** True when $user is an active admin and no other active admin exists (reads the database). */
    public static function isLastAdmin(User $user): bool
    {
        return $user->role === Role::Admin
            && ! User::query()
                ->where('role', Role::Admin->value)
                ->whereNull('deleted_at')
                ->whereKeyNot($user->getKey())
                ->exists();
    }
}
