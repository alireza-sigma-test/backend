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

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /**
     * Never your own role. The last admin demoting themselves locks every admin
     * function out of the system with no recovery short of the database.
     */
    public function updateRole(User $user, User $target): bool
    {
        return $user->hasRole(UserRole::Admin->value) && $user->id !== $target->id;
    }
}
