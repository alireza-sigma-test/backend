<?php

// app/Actions/Notifications/MarkNotificationRead.php

namespace App\Actions\Notifications;

use App\Models\User;

final class MarkNotificationRead
{
    /**
     * Marks one of the caller's own notifications read and returns their new
     * unread count.
     *
     * Scoped through $user->notifications() and then firstOrFail(), so an id
     * belonging to somebody else is indistinguishable from one that does not
     * exist: both raise ModelNotFoundException, which bootstrap/app.php renders
     * as a plain 404. **404, not 403** — this application's standing rule is
     * that existence is never disclosed, and the alternative would turn the
     * endpoint into an oracle for "was this person notified about something".
     */
    public function handle(User $user, string $id): int
    {
        $user->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return $user->unreadNotifications()->count();
    }
}
