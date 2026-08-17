<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Reviewers only, per API.md §06: an edit changes what there is to review but not the
 * decision queue.
 */
final class ProposalUpdated extends ProposalBroadcast
{
    public function type(): string
    {
        return 'proposal.updated';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('role.reviewer')];
    }
}
