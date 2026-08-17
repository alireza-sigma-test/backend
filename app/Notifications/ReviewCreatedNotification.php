<?php

namespace App\Notifications;

/**
 * The author and admins. The rating is deliberately absent — it would hand the author
 * a reviewer's score before the decision, which the review screen also withholds.
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
