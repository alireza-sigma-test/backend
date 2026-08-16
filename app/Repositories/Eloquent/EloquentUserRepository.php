<?php

// app/Repositories/Eloquent/EloquentUserRepository.php

namespace App\Repositories\Eloquent;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
}
