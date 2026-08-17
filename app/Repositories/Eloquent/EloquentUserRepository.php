<?php

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
        // Not User::role(...): the model's own role() instance method shadows Spatie's
        // scopeRole, making the static call a fatal.
        return User::whereHas('roles', fn ($q) => $q->where('name', $role->value))->count();
    }

    public function withRoles(UserRole ...$roles): Collection
    {
        // Same shadowing trap as countWithRole().
        $names = array_map(fn (UserRole $role) => $role->value, $roles);

        return User::whereHas('roles', fn ($q) => $q->whereIn('name', $names))->get();
    }
}
