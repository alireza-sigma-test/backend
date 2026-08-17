<?php

namespace App\Actions\Notifications;

use App\Models\User;

final class MarkNotificationRead
{
    /**
     * Returns the caller's new unread count. Scoped then firstOrFail()'d, so someone
     * else's id 404s exactly as a nonexistent one does — never 403, which would be an
     * oracle for "was this person notified about something".
     */
    public function handle(User $user, string $id): int
    {
        $user->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return $user->unreadNotifications()->count();
    }
}
