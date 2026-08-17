<?php

namespace App\Repositories\Contracts;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Read surface for User. Queries only — writes go through Actions. */
interface UserRepository
{
    public function paginate(): LengthAwarePaginator;

    /** Users holding the given role. Feeds the public stats endpoint. */
    public function countWithRole(UserRole $role): int;

    /**
     * Models rather than ids because Notification::send() needs notifiables.
     * Unbounded by design — a paginated recipient list would notify only page one.
     *
     * @return Collection<int, User>
     */
    public function withRoles(UserRole ...$roles): Collection;
}
