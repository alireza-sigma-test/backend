<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * The author and admins, never other reviewers — API.md §06 keeps one reviewer's
 * rating off the others' screens. The rating is absent from the payload for the same
 * reason; a client that needs it refetches through the policy-checked API.
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
