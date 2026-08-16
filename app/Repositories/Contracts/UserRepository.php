<?php

// app/Repositories/Contracts/UserRepository.php

namespace App\Repositories\Contracts;

use App\Enums\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for User. Queries only — writes go through Actions using
 * Eloquent directly, which keeps this interface honest.
 */
interface UserRepository
{
    public function paginate(): LengthAwarePaginator;

    /** Users holding the given role. Feeds the public stats endpoint. */
    public function countWithRole(UserRole $role): int;
}
