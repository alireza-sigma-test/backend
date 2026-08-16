<?php

// app/Repositories/Eloquent/EloquentNotificationRepository.php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\NotificationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentNotificationRepository implements NotificationRepository
{
    public function paginate(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        // Built from $user->notifications(), never from a bare
        // DatabaseNotification::query() with a where. The relation carries the
        // notifiable_type/notifiable_id pair, so the scoping cannot be
        // forgotten by whoever adds the next filter.
        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        // Newest first is the relation's own default ordering; stated here so a
        // reader does not have to know that.
        return $query->latest()->paginate(max(1, min($perPage, 50)))->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
