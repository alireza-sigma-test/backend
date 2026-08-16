<?php

// app/Events/ReviewCreated.php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * A reviewer rated a proposal.
 *
 * The author hears about their own proposal; admins hear about all of them,
 * because reviews are what makes a proposal ready to decide. Reviewers are not
 * on this list — API.md §06 keeps one reviewer's rating off the other
 * reviewers' screens.
 *
 * The rating itself is deliberately absent from the payload: the shape is
 * shared with the other three events, and the author is not entitled to a
 * reviewer's score arriving unbidden. A client that needs it refetches the
 * proposal through the API, which applies the usual policy.
 */
final class ReviewCreated extends ProposalBroadcast
{
    public function type(): string
    {
        return 'review.created';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->proposal->user_id),
            new PrivateChannel('role.admin'),
        ];
    }
}
