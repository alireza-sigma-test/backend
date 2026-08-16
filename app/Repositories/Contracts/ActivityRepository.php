<?php

// app/Repositories/Contracts/ActivityRepository.php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for the activity feed. There is no write surface: the feed is
 * derived from proposals, status changes and reviews rather than stored.
 *
 * The pair to NotificationRepository, and the distinction is the whole point —
 * **notifications are addressed to you; activity is everything you may see.**
 * Both take the viewer and scope by them, but by different rules: a
 * notification is scoped by who it was sent to, activity by the same
 * visibility the proposal list uses.
 */
interface ActivityRepository
{
    public function paginate(User $viewer, int $perPage): LengthAwarePaginator;
}
