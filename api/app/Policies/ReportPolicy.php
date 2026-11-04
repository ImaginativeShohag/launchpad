<?php

namespace App\Policies;

use App\Models\User;

class ReportPolicy
{
    /** Class-level: reviewers and admins work the report queue (taking a product down stays admin only, see ProductPolicy::takeDown). */
    public function review(User $user): bool
    {
        return $user->role->isStaff();
    }
}
