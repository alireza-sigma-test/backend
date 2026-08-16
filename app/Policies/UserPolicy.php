<?php

// app/Policies/UserPolicy.php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    // Verification also gates every mutating ability below, the same
    // conjunct ProposalPolicy::create/update/delete/review/changeStatus and
    // ReviewPolicy::update/delete already carry. No bug today — both writes
    // sit inside routes/api.php's `verified` group, so the middleware refuses
    // an unverified admin first — but that made this policy the one place
    // the conjunct was solved a different way (by omission) instead of not at
    // all, and left the middleware as the only guard instead of defence in
    // depth. There is no `can` object for User, so nothing was lying to a
    // client either way; this is purely about the two guards agreeing.
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
     * Same admin gate as create/updateRole. Whether the target is actually
     * eligible for a reissued invite — never claimed, and invited through
     * this flow in the first place rather than self-registered — is a state
     * invariant on $target, not a question of who $user is, so it lives in
     * ReinviteUser as a thrown exception instead of here: the same split
     * ChangeUserRole draws between "may this actor attempt it" (a policy,
     * checked against a single row, no lock needed) and "does the state
     * allow it" (the action, which alone knows the write it is about to make).
     */
    public function reinvite(User $user, User $target): bool
    {
        return $user->hasVerifiedEmail() && $user->hasRole(UserRole::Admin->value);
    }
}
