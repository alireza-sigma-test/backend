<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for the activity feed, which is derived rather than stored.
 *
 * The pair to NotificationRepository: notifications are addressed to you, activity is
 * everything you may see. Both scope by viewer, but by different rules.
 */
interface ActivityRepository
{
    public function paginate(User $viewer, int $perPage): LengthAwarePaginator;
}
