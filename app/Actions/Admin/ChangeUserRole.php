<?php

// app/Actions/Admin/ChangeUserRole.php

namespace App\Actions\Admin;

use App\Enums\UserRole;
use App\Models\User;

final class ChangeUserRole
{
    public function handle(User $target, UserRole $role): User
    {
        // syncRoles, not assignRole — assignRole adds a role without removing
        // the old one, leaving the user holding two, and UserResource::role()
        // derives a single value from the pivot, so the result would be
        // silently arbitrary.
        $target->syncRoles([$role->value]);

        return $target->fresh()->load('roles');
    }
}
