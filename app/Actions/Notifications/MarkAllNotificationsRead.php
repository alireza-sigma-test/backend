<?php

namespace App\Actions\Notifications;

use App\Models\User;

final class MarkAllNotificationsRead
{
    /** Returns the new unread count, which is always 0 — the endpoint's
     *  contract is the header, and reading it back keeps the two consistent
     *  even if this ever grows a filter. */
    public function handle(User $user): int
    {
        $user->unreadNotifications->markAsRead();

        return $user->unreadNotifications()->count();
    }
}
