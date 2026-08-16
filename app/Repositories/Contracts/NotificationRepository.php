<?php

// app/Repositories/Contracts/NotificationRepository.php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for the notifications table. Queries only — writes go through
 * Actions using Eloquent directly, which keeps this interface honest.
 *
 * Every method takes the owner and scopes to them. There is no unscoped read
 * here on purpose: a notification is addressed to exactly one person, and an
 * endpoint that could return someone else's is the only real defect this
 * feature can have.
 */
interface NotificationRepository
{
    public function paginate(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator;

    public function unreadCount(User $user): int;
}
