<?php

// app/Repositories/Contracts/UserRepository.php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for User. Queries only — writes go through Actions using
 * Eloquent directly, which keeps this interface honest.
 */
interface UserRepository
{
    public function paginate(): LengthAwarePaginator;
}
