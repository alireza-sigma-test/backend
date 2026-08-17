<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    // The hasVerifiedEmail() conjunct on every mutating ability below duplicates
    // routes/api.php's `verified` group on purpose: defence in depth, and it keeps
    // this policy consistent with ProposalPolicy and ReviewPolicy.
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->hasRole(UserRole::Admin->value);
    }

    /**
     * Never your own role. The last admin demoting themselves locks every admin
     * function out of the system with no recovery short of the database.
     */
    public function updateRole(User $user, User $target): bool
    {
        return $user->hasVerifiedEmail() && $user->hasRole(UserRole::Admin->value) && $user->id !== $target->id;
    }

    /**
     * Only "may this actor attempt it". Whether $target is eligible is a state
     * invariant, so it lives in ReinviteUser as a thrown exception.
     */
    public function reinvite(User $user, User $target): bool
    {
        return $user->hasVerifiedEmail() && $user->hasRole(UserRole::Admin->value);
    }
}
