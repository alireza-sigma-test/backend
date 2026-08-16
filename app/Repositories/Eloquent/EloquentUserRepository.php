<?php

// app/Repositories/Eloquent/EloquentUserRepository.php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentUserRepository implements UserRepository
{
    public function paginate(): LengthAwarePaginator
    {
        return User::with('roles')->paginate();
    }
}
