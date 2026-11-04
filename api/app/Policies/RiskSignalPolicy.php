<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Who may work with anti-cheat evidence. Class-level only: the staff API judges the role before it looks anything up. */
class RiskSignalPolicy
{
    /** Reviewers and admins read the evidence and triage signals. */
    public function review(User $user): bool
    {
        return $user->role->isStaff();
    }

    /** Only admins act on it (void votes, suspend members). */
    public function intervene(User $user): bool
    {
        return $user->role === Role::Admin;
    }
}
