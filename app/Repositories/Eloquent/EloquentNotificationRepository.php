<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\NotificationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentNotificationRepository implements NotificationRepository
{
    public function paginate(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        // The relation, never a bare DatabaseNotification::query(): it carries the
        // notifiable_type/notifiable_id pair, so the scoping cannot be forgotten.
        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        // Newest first is the relation's own default ordering.
        return $query->latest()->paginate(max(1, min($perPage, 50)))->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
