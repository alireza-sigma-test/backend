<?php

// app/Repositories/Contracts/UserRepository.php

namespace App\Repositories\Contracts;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read surface for User. Queries only — writes go through Actions using
 * Eloquent directly, which keeps this interface honest.
 */
interface UserRepository
{
    public function paginate(): LengthAwarePaginator;

    /** Users holding the given role. Feeds the public stats endpoint. */
    public function countWithRole(UserRole $role): int;

    /**
     * Every user holding any of the given roles.
     *
     * Feeds ActivityNotifier's recipient lists, which is why it returns models
     * rather than ids — Notification::send() needs notifiables. Unbounded by
     * design: the roles here are staff roles (reviewers, admins), and a
     * paginated recipient list would silently notify only the first page.
     *
     * @return Collection<int, User>
     */
    public function withRoles(UserRole ...$roles): Collection;
}
