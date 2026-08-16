<?php

// app/Repositories/Eloquent/EloquentUserRepository.php

namespace App\Repositories\Eloquent;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class EloquentUserRepository implements UserRepository
{
    public function paginate(): LengthAwarePaginator
    {
        return User::with('roles')->paginate();
    }

    public function countWithRole(UserRole $role): int
    {
        // Not User::role(...): User defines its own instance method named
        // role() (it feeds UserResource), which shadows Spatie's scopeRole,
        // so a static call through it is a hard "cannot be called
        // statically" error. This exact trap already bit ChangeUserRole.
        return User::whereHas('roles', fn ($q) => $q->where('name', $role->value))->count();
    }

    public function withRoles(UserRole ...$roles): Collection
    {
        // Same shadowing trap as countWithRole(): User::role(...) is a hard
        // error because the model defines its own instance method named role().
        $names = array_map(fn (UserRole $role) => $role->value, $roles);

        return User::whereHas('roles', fn ($q) => $q->whereIn('name', $names))->get();
    }
}
