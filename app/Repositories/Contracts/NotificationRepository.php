<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for the notifications table. Queries only — writes go through Actions.
 * Every method takes the owner and scopes to them; there is deliberately no unscoped
 * read, because returning someone else's notification is this feature's one real defect.
 */
interface NotificationRepository
{
    public function paginate(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator;

    public function unreadCount(User $user): int;
}
