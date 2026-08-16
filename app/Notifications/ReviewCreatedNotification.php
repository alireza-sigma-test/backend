<?php

// app/Notifications/ReviewCreatedNotification.php

namespace App\Notifications;

/**
 * Sent to the proposal's author and to admins when a reviewer rates.
 *
 * The rating is deliberately absent. The author's own notification would
 * otherwise hand them a reviewer's score before the decision is made, which
 * is not what the review screen shows them either.
 */
final class ReviewCreatedNotification extends ProposalActivity
{
    public function type(): string
    {
        return 'review.created';
    }

    protected function title(): string
    {
        return 'New review';
    }

    protected function body(): string
    {
        return $this->quoted();
    }
}
